import SwiftUI

// androidApp `chat/ChatBusinessUi.kt` (QuickReplies, QuickReplySuggestions, BusinessPage, QuickReplySheet) and
// ChatBacklogUi's `BusinessStatsCard` — my business tools: the business card, message statistics, opening hours (shown
// to people who write to me), the welcome message for new customers, the away message when I'm closed, and quick
// replies typed with «/».

/// My quick replies, loaded once and shared by the composer and the business page.
@MainActor
final class QuickReplies: ObservableObject {
    static let shared = QuickReplies()
    @Published private(set) var list: [QuickReplyDto]?

    func load(force: Bool = false) async {
        if list != nil && !force { return }
        list = ((try? await ChatApi.quickReplies().data) ?? []).sorted { $0.shortcut < $1.shortcut }
    }

    func set(_ replies: [QuickReplyDto]) { list = replies.sorted { $0.shortcut < $1.shortcut } }
}

/// While I type «/…»: my quick replies that start so (or, with none yet, a way to set them up).
struct QuickReplySuggestions: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var store = QuickReplies.shared
    @ObservedObject private var ch = Ch.shared
    let query: String?
    let onPick: (QuickReplyDto) -> Void

    var body: some View {
        let all = store.list
        let matches = query.flatMap { q in all.map { Array($0.filter { $0.shortcut.hasPrefix(q.lowercased()) }.prefix(6)) } } ?? []
        let offerSetup = query == "" && all?.isEmpty == true
        VStack(spacing: 0) {
            if offerSetup {
                Button { host.push(.business) } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "bolt.fill").font(.system(size: 19)).foregroundStyle(ch.red)
                        Text(L("ch_quick_replies_setup")).font(.dorr(14, .bold)).foregroundStyle(ch.red)
                        Spacer()
                    }
                    .padding(.horizontal, 14).padding(.vertical, 10).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            ForEach(Array(matches.enumerated()), id: \.element.id) { i, reply in
                Button { onPick(reply) } label: {
                    HStack(spacing: 10) {
                        Text(verbatim: "/\(reply.shortcut)").font(.dorr(13, .heavy)).foregroundStyle(ch.red)
                            .padding(.horizontal, 8).padding(.vertical, 3).background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 10))
                        Text(verbatim: reply.body).font(.dorr(14)).foregroundStyle(ch.ink).lineLimit(1)
                        Spacer(minLength: 0)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 9).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .chStagger(i)
            }
        }
        .padding(.vertical, (matches.isEmpty && !offerSetup) ? 0 : 6)
        .background { if !matches.isEmpty || offerSetup { RoundedRectangle(cornerRadius: 20).fill(ch.surface).shadow(color: .black.opacity(0.15), radius: 10, y: 3) } }
        .padding(.bottom, (matches.isEmpty && !offerSetup) ? 0 : 6)
        .animation(.spring(response: 0.35, dampingFraction: 0.8), value: matches.count)
        .task(id: query != nil) { if query != nil { await store.load() } }
    }
}

// MARK: - business page

private enum BizPanel { case card, stats, hours, welcome, away, replies }

struct BusinessPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var store = QuickReplies.shared
    @State private var loaded = false
    @State private var unavailable = false
    @State private var welcomeOn = false
    @State private var welcome = ""
    @State private var awayOn = false
    @State private var away = ""
    @State private var awayMode = "outside_hours"
    @State private var about = ""
    @State private var address = ""
    @State private var email = ""
    @State private var website = ""
    @State private var days: [BusinessDayDto] = []
    @State private var saving = false
    @State private var editingReply: QuickReplyDto?
    @State private var newReply = false
    @State private var panel: BizPanel?

    var body: some View {
        ChSettingsPage(title: L("ch_business_title"), onBack: { host.pop() }) {
            if !loaded {
                WaSkeleton().frame(height: 360).clipShape(RoundedRectangle(cornerRadius: 22)).padding(14)
                Spacer()
            } else if unavailable {
                ChEmptyState(icon: "storefront.fill", title: L("ch_business_title"), text: L("ch_business_unavailable"))
            } else {
                ScrollView { content.padding(.horizontal, 14).padding(.top, 6).padding(.bottom, 16) }
            }
        }
        .task { await load() }
        .dorrDialog(isPresented: Binding(get: { panel != nil || newReply || editingReply != nil },
                                         set: { if !$0 { panel = nil; newReply = false; editingReply = nil } }), bottom: true) {
            if newReply || editingReply != nil {
                QuickReplySheet(host: host, reply: editingReply) { newReply = false; editingReply = nil }
            } else {
                panelView
            }
        }
    }

    private var content: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .top, spacing: 10) {
                PinkIcon(icon: "info.circle.fill")
                Text(L("ch_business_intro")).font(.dorr(12.5)).foregroundStyle(Ch.shared.mut).lineSpacing(4).padding(.top, 6).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(4)
            VStack(spacing: 0) {
                PrivacySettingsRow(icon: "storefront.fill", title: L("bk_business_card")) { panel = .card }
                PrivacySettingsRow(icon: "chart.xyaxis.line", title: L("bk_business_stats")) { panel = .stats }
                PrivacySettingsRow(icon: "clock.fill", title: L("ch_business_hours")) { panel = .hours }
                PrivacySettingsRow(icon: "hand.wave.fill", title: L("ch_business_welcome")) { panel = .welcome }
                PrivacySettingsRow(icon: "sun.haze.fill", title: L("ch_business_away")) { panel = .away }
                PrivacySettingsRow(icon: "bolt.fill", title: L("ch_quick_replies"), last: true) { panel = .replies }
            }
            .padding(.horizontal, 8).padding(.vertical, 4)
            .settingsSurface(18, elevation: 8)
        }
    }

    @ViewBuilder
    private var panelView: some View {
        let close = { panel = nil }
        switch panel {
        case .card:
            BusinessCardSheet(about: about, address: address, email: email, website: website, busy: saving) { a, d, e, w in
                about = a; address = d; email = e; website = w
                save(then: close)
            }
        case .stats:
            ChSheetCard {
                Text(L("bk_business_stats")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
                BusinessStatsCard()
            }
        case .hours:
            BusinessHoursSheet(days: days, busy: saving) { next in
                days = next
                save(then: close)
            }
        case .welcome:
            BusinessMessageSheet(title: L("ch_business_welcome"), icon: "hand.wave.fill", toggle: L("ch_business_welcome_on"), toggleSub: L("ch_business_welcome_sub"),
                                 hint: L("ch_business_welcome_hint"), on: welcomeOn, message: welcome, mode: nil, busy: saving) { on, msg, _ in
                welcomeOn = on; welcome = msg
                save(then: close)
            }
        case .away:
            BusinessMessageSheet(title: L("ch_business_away"), icon: "sun.haze.fill", toggle: L("ch_business_away_on"), toggleSub: L("ch_business_away_sub"),
                                 hint: L("ch_business_away_hint"), on: awayOn, message: away, mode: awayMode, busy: saving) { on, msg, mode in
                awayOn = on; away = msg; awayMode = mode ?? awayMode
                save(then: close)
            }
        case .replies:
            ChSheetCard {
                Text(L("ch_quick_replies")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Text(L("ch_quick_replies_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.top, 6).padding(.bottom, 14)
                ScrollView {
                    VStack(spacing: 8) {
                        ForEach(Array((store.list ?? []).enumerated()), id: \.element.id) { i, reply in
                            Button { editingReply = reply } label: {
                                HStack(spacing: 10) {
                                    Text(verbatim: "/\(reply.shortcut)").font(.dorr(13.5, .heavy)).foregroundStyle(ch.red)
                                    Text(verbatim: reply.body).font(.dorr(13.5)).foregroundStyle(ch.ink).lineLimit(2).multilineTextAlignment(.leading).frame(maxWidth: .infinity, alignment: .leading)
                                    Image(systemName: "pencil").font(.system(size: 15)).foregroundStyle(ch.soft)
                                }
                                .padding(12)
                                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .chStagger(i)
                        }
                        Button { newReply = true } label: {
                            HStack(spacing: 8) {
                                Image(systemName: "plus").font(.system(size: 17, weight: .semibold))
                                Text(L("ch_quick_reply_new")).font(.dorr(15, .heavy))
                            }
                            .foregroundStyle(ch.red)
                            .frame(maxWidth: .infinity).padding(14)
                            .background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
                .frame(maxHeight: 520)
            }
        case nil:
            EmptyView()
        }
    }

    private func load() async {
        do {
            if let b = try await ChatApi.business().data {
                let p = b.profile
                welcomeOn = p.welcomeEnabled ?? false
                welcome = p.welcomeMessage ?? ""
                awayOn = p.awayEnabled ?? false
                away = p.awayMessage ?? ""
                awayMode = p.awayMode ?? "outside_hours"
                about = p.about ?? ""
                address = p.address ?? ""
                email = p.email ?? ""
                website = p.website ?? ""
                let hours = p.hours ?? []
                days = hours.isEmpty ? Array(repeating: BusinessDayDto(open: false, from: "09:00", to: "17:00"), count: 7) : hours
                store.set(b.quickReplies ?? [])
            }
        } catch {
            unavailable = error.apiFailure.errorCode == "chat_business_unavailable"
            if !unavailable { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
        }
        loaded = true
    }

    /// Saves the whole profile (each sheet changed its part first); `then` once it's saved.
    private func save(then: (() -> Void)? = nil) {
        saving = true
        func opt(_ s: String) -> JSONValue {
            let t = s.trimmingCharacters(in: .whitespacesAndNewlines)
            return t.isEmpty ? .null : .string(t)
        }
        let site = website.trimmingCharacters(in: .whitespaces)
        let body: [String: JSONValue] = [
            "welcome_enabled": .bool(welcomeOn), "welcome_message": .string(welcome.trimmingCharacters(in: .whitespacesAndNewlines)),
            "away_enabled": .bool(awayOn), "away_message": .string(away.trimmingCharacters(in: .whitespacesAndNewlines)), "away_mode": .string(awayMode),
            "about": opt(about), "address": opt(address), "email": opt(email),
            "website": site.isEmpty ? .null : .string(site.hasPrefix("http") ? site : "https://\(site)"),
            "hours": .array(days.map { .object(["open": .bool($0.open), "from": .string($0.from), "to": .string($0.to)]) }),
            // The hours are in my own time zone.
            "timezone": .string(TimeZone.current.identifier),
        ]
        Task {
            do {
                _ = try await ChatApi.updateBusiness(body)
                host.showToast(L("ch_saved"))
                then?()
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            saving = false
        }
    }
}

/// About, address, e-mail and website (backlog 111).
private struct BusinessCardSheet: View {
    @ObservedObject private var ch = Ch.shared
    let about: String
    let address: String
    let email: String
    let website: String
    let busy: Bool
    let onSave: (String, String, String, String) -> Void
    @State private var a = ""
    @State private var d = ""
    @State private var e = ""
    @State private var w = ""
    @State private var started = false

    var body: some View {
        ChSheetCard {
            Text(L("bk_business_card")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
            ChField(placeholder: L("bk_business_about"), text: $a, icon: "info.circle.fill", lines: 2...5, limit: 500)
            ChField(placeholder: L("bk_business_address"), text: $d, icon: "mappin.circle.fill", limit: 300).padding(.top, 10)
            ChField(placeholder: L("bk_business_email"), text: $e, icon: "envelope.fill", limit: 150, keyboard: .emailAddress).padding(.top, 10)
            ChField(placeholder: L("bk_business_website"), text: $w, icon: "globe", limit: 255, keyboard: .URL).padding(.top, 10)
            ChPrimaryButton(text: L("ch_save"), enabled: !busy) { onSave(a, d, e, w) }.padding(.top, 16)
        }
        .onAppear {
            guard !started else { return }
            started = true
            a = about; d = address; e = email; w = website
        }
    }
}

/// The opening hours, a day a line (Sunday first); a time opens the wheel in place.
private struct BusinessHoursSheet: View {
    @ObservedObject private var ch = Ch.shared
    let days: [BusinessDayDto]
    let busy: Bool
    let onSave: ([BusinessDayDto]) -> Void
    @State private var draft: [BusinessDayDto] = []
    /// (day, is «from»)
    @State private var picking: (Int, Bool)?
    @State private var started = false

    var body: some View {
        Group {
            if let (day, isFrom) = picking, draft.indices.contains(day) {
                BusinessTimeSheet(initial: isFrom ? draft[day].from : draft[day].to) { text in
                    if isFrom { draft[day].from = text } else { draft[day].to = text }
                    picking = nil
                }
            } else {
                ChSheetCard {
                    Text(L("ch_business_hours")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
                    ForEach(draft.indices, id: \.self) { i in
                        HStack(spacing: 0) {
                            Text(verbatim: dayName(i)).font(.dorr(14, .bold)).foregroundStyle(ch.ink).frame(width: 86, alignment: .leading)
                            if draft[i].open {
                                timeChip(draft[i].from) { picking = (i, true) }
                                Text(verbatim: "–").foregroundStyle(ch.mut).padding(.horizontal, 6)
                                timeChip(draft[i].to) { picking = (i, false) }
                            } else {
                                Text(L("ch_business_closed")).font(.dorr(13)).foregroundStyle(ch.soft)
                            }
                            Spacer()
                            Toggle("", isOn: Binding(get: { draft[i].open }, set: { draft[i].open = $0 })).labelsHidden().tint(ch.red)
                        }
                        .padding(.vertical, 6)
                    }
                    ChPrimaryButton(text: L("ch_save"), enabled: !busy) { onSave(draft) }.padding(.top, 16)
                }
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            draft = days.isEmpty ? Array(repeating: BusinessDayDto(open: false, from: "09:00", to: "17:00"), count: 7) : days
        }
    }

    private func timeChip(_ time: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: time).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).environment(\.layoutDirection, .leftToRight)
                .padding(.horizontal, 10).padding(.vertical, 5).background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 10))
        }
        .buttonStyle(.plain)
    }

    /// Sunday first, in the app's language.
    private func dayName(_ index: Int) -> String {
        var cal = Calendar(identifier: .gregorian)
        cal.locale = AppLocale.locale
        return cal.weekdaySymbols[index % 7]
    }
}

/// The welcome or the away message: on / off, its text, and for «away» when it goes.
private struct BusinessMessageSheet: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    let icon: String
    let toggle: String
    let toggleSub: String
    let hint: String
    let on: Bool
    let message: String
    let mode: String?
    let busy: Bool
    let onSave: (Bool, String, String?) -> Void
    @State private var draftOn = false
    @State private var draft = ""
    @State private var draftMode: String?
    @State private var started = false

    var body: some View {
        ChSheetCard {
            Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
            ToggleRow(icon: icon, title: toggle, checked: draftOn, subtitle: toggleSub) { draftOn = $0 }
            if draftMode != nil {
                HStack(spacing: 8) {
                    modeChip(L("ch_business_away_outside"), "outside_hours")
                    modeChip(L("ch_business_away_always"), "always")
                }
                .padding(.top, 10)
            }
            ChField(placeholder: hint, text: $draft, icon: "text.bubble.fill", lines: 2...6, limit: 1000).padding(.top, 10)
            ChPrimaryButton(text: L("ch_save"), enabled: !busy) { onSave(draftOn, draft, draftMode) }.padding(.top, 16)
        }
        .onAppear {
            guard !started else { return }
            started = true
            draftOn = on
            draft = message
            draftMode = mode
        }
    }

    private func modeChip(_ label: String, _ value: String) -> some View {
        let selected = draftMode == value
        return Button { draftMode = value } label: {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : ch.ink).multilineTextAlignment(.center)
                .frame(maxWidth: .infinity).padding(.vertical, 10)
                .background(selected ? ch.red : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .animation(.easeOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

/// An hour of the day (HH:mm, the phone's 24-hour wheel).
struct BusinessTimeSheet: View {
    @ObservedObject private var ch = Ch.shared
    let initial: String
    let onPick: (String) -> Void
    @State private var time = Date()

    var body: some View {
        ChSheetCard {
            DatePicker("", selection: $time, displayedComponents: .hourAndMinute)
                .datePickerStyle(.wheel).labelsHidden().environment(\.locale, Locale(identifier: "en_GB")).frame(maxWidth: .infinity)
            ChPrimaryButton(text: L("ch_save")) {
                let f = DateFormatter()
                f.locale = Locale(identifier: "en_US_POSIX")
                f.dateFormat = "HH:mm"
                onPick(f.string(from: time))
            }
        }
        .onAppear {
            let f = DateFormatter()
            f.locale = Locale(identifier: "en_US_POSIX")
            f.dateFormat = "HH:mm"
            if let d = f.date(from: initial) {
                let parts = Calendar.current.dateComponents([.hour, .minute], from: d)
                time = Calendar.current.date(bySettingHour: parts.hour ?? 9, minute: parts.minute ?? 0, second: 0, of: Date()) ?? Date()
            }
        }
    }
}

/// New or change a quick reply: «/shortcut» and its text.
private struct QuickReplySheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let reply: QuickReplyDto?
    let onDismiss: () -> Void
    @State private var shortcut = ""
    @State private var body_ = ""
    @State private var busy = false
    @State private var started = false
    @State private var confirmDelete = false

    var body: some View {
        if confirmDelete, let reply {
            ConfirmDialog(icon: "trash.fill", title: L("ch_quick_reply_delete_title"), message: L("ch_quick_reply_delete_sub"), loading: busy, confirmLabel: L("ch_delete"),
                          onConfirm: {
                              confirmDelete = false
                              done(L("ch_quick_reply_deleted")) { _ = try await ChatApi.deleteQuickReply(reply.id) }
                          }, onDismiss: { if !busy { confirmDelete = false } })
        } else {
            form
        }
    }

    private var form: some View {
        ChSheetCard {
            Text(L(reply == nil ? "ch_quick_reply_new" : "ch_quick_reply_edit")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
            ChField(placeholder: L("ch_quick_reply_shortcut"), text: Binding(get: { shortcut }, set: { v in
                var t = v.replacingOccurrences(of: " ", with: "")
                if t.hasPrefix("/") { t.removeFirst() }
                shortcut = String(t.prefix(32))
            }), icon: "bolt.fill", limit: 33)
            ChField(placeholder: L("ch_quick_reply_body"), text: $body_, icon: "text.alignleft", lines: 3...8, limit: 4000).padding(.top, 10)
            ChPrimaryButton(text: L("ch_save"), enabled: !busy && !shortcut.isEmpty && !body_.trimmingCharacters(in: .whitespaces).isEmpty) {
                done(L("ch_saved")) {
                    let b = body_.trimmingCharacters(in: .whitespacesAndNewlines)
                    if let reply { _ = try await ChatApi.updateQuickReply(reply.id, shortcut: shortcut, body: b) } else { _ = try await ChatApi.addQuickReply(shortcut: shortcut, body: b) }
                }
            }
            .padding(.top, 16)
            if let reply {
                Button { confirmDelete = true } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "trash").font(.system(size: 17))
                        Text(L("ch_delete")).font(.dorr(15, .heavy))
                    }
                    .foregroundStyle(ch.danger).frame(maxWidth: .infinity).padding(.horizontal, 26).padding(.vertical, 14)
                    .background(ch.danger.opacity(0.14), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            shortcut = reply?.shortcut ?? ""
            body_ = reply?.body ?? ""
        }
    }

    private func done(_ toast: String, _ action: @escaping () async throws -> Void) {
        busy = true
        Task {
            do {
                try await action()
                await QuickReplies.shared.load(force: true)
                host.showToast(toast)
                onDismiss()
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            busy = false
        }
    }
}

/// My messages to customers and theirs to me, over 7 / 30 / 90 days (WhatsApp Business's statistics).
struct BusinessStatsCard: View {
    @ObservedObject private var ch = Ch.shared
    @Environment(\.wa) private var wa
    @State private var days = 30
    @State private var stats: BusinessStatsDto?

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 6) {
                ForEach([7, 30, 90], id: \.self) { d in
                    Button { days = d } label: {
                        Text(L("bk_days", d)).font(.dorr(13, .bold)).foregroundStyle(days == d ? .white : ch.ink)
                            .padding(.horizontal, 12).padding(.vertical, 8).background(days == d ? ch.red : ch.surfaceMuted, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            VStack(spacing: 0) {
                row("paperplane.fill", L("bk_stats_sent"), stats?.sent)
                row("checkmark.circle.fill", L("bk_stats_delivered"), stats?.delivered)
                row("eye.fill", L("bk_stats_read"), stats?.read)
                row("tray.fill", L("bk_stats_received"), stats?.received)
                row("bubble.left", L("bk_stats_chats"), stats?.chats, last: true)
            }
            .padding(.horizontal, 8).padding(.vertical, 4)
            .settingsSurface(18, elevation: 8)
        }
        .task(id: days) { stats = try? await BacklogApi.businessStats(days: days).data }
    }

    private func row(_ icon: String, _ title: String, _ value: Int?, last: Bool = false) -> some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                PinkIcon(icon: icon)
                Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: value.map(String.init) ?? "…").font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 4).padding(.vertical, 12)
            if !last { Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
        }
    }
}

/// Under a business's name: «Open now» / «Closed · opens 09:00».
func businessStatus(_ business: BusinessHoursDto) -> String {
    if business.openNow == true { return L("ch_business_open_now") }
    guard let next = nextOpening(business) else { return L("ch_business_closed_now") }
    return L("ch_business_opens_at", next)
}

/// When it opens next, in my own time («09:00» today / tomorrow, or «Sun 09:00»).
private func nextOpening(_ business: BusinessHoursDto) -> String? {
    let zone = business.timezone.flatMap(TimeZone.init(identifier:)) ?? TimeZone(identifier: "UTC")!
    var cal = Calendar(identifier: .gregorian)
    cal.timeZone = zone
    let now = Date()
    let hours = business.hours ?? []
    for offset in 0...7 {
        guard let day = cal.date(byAdding: .day, value: offset, to: cal.startOfDay(for: now)) else { continue }
        // 0 = Sunday, as the server keeps them.
        let index = cal.component(.weekday, from: day) - 1
        guard index < hours.count, hours[index].open else { continue }
        let parts = hours[index].from.split(separator: ":").compactMap { Int($0) }
        guard let opens = cal.date(bySettingHour: parts.first ?? 0, minute: parts.count > 1 ? parts[1] : 0, second: 0, of: day), opens > now else { continue }
        let time = DateFormatter()
        time.locale = AppLocale.locale
        time.timeStyle = .short
        time.dateStyle = .none
        if offset <= 1 { return time.string(from: opens) }
        let weekday = DateFormatter()
        weekday.locale = AppLocale.locale
        weekday.dateFormat = "EEE"
        return weekday.string(from: opens) + " " + time.string(from: opens)
    }
    return nil
}
