import SwiftUI

// androidApp `chat/ChatAttentionUi.kt` + `chat/LocalIntelligence.kt` — my communication rules (spec 146, 151, 155), one
// page: the context I'm in now; what interrupts me, what waits for the summary and what waits silently, per source; how
// often the summary comes; the people who always get through; when calls ring; on-my-phone-only intelligence (156); and
// what each context does. Only notifications change — every message still arrives.

private func contextLabel(_ key: String?) -> String {
    switch key {
    case "home": return L("at_ctx_home")
    case "work": return L("at_ctx_work")
    case "meeting": return L("at_ctx_meeting")
    case "travel": return L("at_ctx_travel")
    case "private": return L("at_ctx_private")
    default: return L("at_ctx_none")
    }
}

private func contextIcon(_ key: String?) -> String {
    switch key {
    case "home": return "house.fill"
    case "work": return "briefcase.fill"
    case "meeting": return "person.3.fill"
    case "travel": return "airplane"
    case "private": return "lock.fill"
    default: return "slider.horizontal.3"
    }
}

private func sourceIcon(_ key: String) -> String {
    switch key {
    case "contacts": return "person.2.fill"
    case "strangers": return "person.fill.questionmark"
    case "groups": return "person.3.fill"
    case "mentions": return "at"
    case "channels": return "megaphone.fill"
    case "money": return "banknote.fill"
    default: return "exclamationmark"
    }
}

private func sourceLabel(_ key: String) -> String {
    let known = ["contacts", "strangers", "groups", "mentions", "channels", "money"]
    return L("at_src_" + (known.contains(key) ? key : "urgent"))
}

private func modeLabel(_ mode: String?) -> String {
    switch mode {
    case "digest": return L("at_mode_digest")
    case "later": return L("at_mode_later")
    default: return L("at_mode_now")
    }
}

private func digestLabel(_ minutes: Int?) -> String {
    let m = minutes ?? 60
    return m < 60 ? L("ch_minutes_n", m) : L("ch_hours_n", m / 60)
}

private func showsLabel(_ value: String?) -> String {
    switch value {
    case "name": return L("at_show_name")
    case "none": return L("at_show_nothing")
    default: return L("at_show_usual")
    }
}

private func callsLabel(_ value: String?) -> String {
    switch value {
    case "through": return L("at_calls_through")
    case "nobody": return L("at_calls_nobody")
    default: return L("at_calls_everyone")
    }
}

private let defaultSources = ["contacts", "strangers", "groups", "mentions", "channels", "money", "urgent"]

private func dayName(_ d: Int, short: Bool) -> String {
    var cal = Calendar(identifier: .gregorian)
    cal.locale = AppLocale.locale
    return (short ? cal.shortWeekdaySymbols : cal.weekdaySymbols)[d % 7]
}

private func contextSummary(_ c: ContextDto) -> String {
    var parts: [String] = []
    if c.disclosure == "name" { parts.append(L("at_show_name")) } else if c.disclosure == "none" { parts.append(L("at_show_nothing")) }
    if c.calls == "through" { parts.append(L("at_calls_through")) } else if c.calls == "nobody" { parts.append(L("at_calls_nobody")) }
    let waiting = (c.attention ?? [:]).values.filter { $0 != "now" }.count
    if waiting > 0 { parts.append(L("at_n_wait", waiting)) }
    return parts.isEmpty ? L("at_all_through") : parts.joined(separator: " · ")
}

private struct RulesSection<Content: View>: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    let sub: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(ch.ink).padding(.leading, 4).padding(.top, 18)
            Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.horizontal, 4).padding(.top, 2).padding(.bottom, 10)
            VStack(spacing: 0, content: content)
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
        }
    }
}

struct CommRulesPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var data: AttentionDto?
    @State private var sheet: String?
    @State private var askDuration: String?
    @State private var pickSource: (String, String)?

    var body: some View {
        ChSettingsPage(title: L("at_title"), onBack: { host.pop() }) {
            ScrollView {
                if let d = data { content(d).padding(16) } else {
                    VStack(spacing: 10) { ForEach(0..<4, id: \.self) { _ in RoundedRectangle(cornerRadius: 22).fill(ch.surfaceMuted).frame(height: 90) } }.padding(16)
                }
            }
        }
        .task { data = try? await LifeApi.attention().data }
        .dorrDialog(isPresented: Binding(get: { sheet != nil || askDuration != nil || pickSource != nil }, set: { if !$0 { sheet = nil; askDuration = nil; pickSource = nil } }),
                    bottom: true) { sheetView }
    }

    @ViewBuilder
    private func content(_ d: AttentionDto) -> some View {
        let active = d.context?.key
        VStack(alignment: .leading, spacing: 0) {
            // The context now (151).
            RulesSection(title: L("at_now"), sub: L("at_now_sub")) {
                PrivacySettingsRow(icon: contextIcon(active), title: contextLabel(active), value: d.context?.until.flatMap { lifeWhen($0) }.map { L("at_until", $0) }, last: true) { sheet = "now" }
            }
            .padding(.top, -18)
            // Attention per source (146).
            let sources = (d.sources ?? []).isEmpty ? defaultSources : d.sources!
            RulesSection(title: L("at_attention"), sub: L("at_attention_sub")) {
                ForEach(sources, id: \.self) { src in
                    let mode = d.attention?[src] ?? "now"
                    PrivacySettingsRow(icon: sourceIcon(src), title: sourceLabel(src), value: modeLabel(mode)) { pickSource = (src, mode) }
                }
                PrivacySettingsRow(icon: "clock.fill", title: L("at_digest_every"), value: digestLabel(d.digestMinutes), last: true) { sheet = "digest" }
            }
            // Always get through (155).
            RulesSection(title: L("at_through"), sub: L("at_through_sub")) {
                let n = d.alwaysThrough?.count ?? 0
                PrivacySettingsRow(icon: "person.2.fill", title: L("at_through"), value: n == 0 ? L("at_through_none") : String(n), last: true) { sheet = "people" }
            }
            // Calls (155).
            let hours = d.callHours
            let hoursOn = hours?.from != nil
            RulesSection(title: L("at_calls"), sub: L("at_calls_sub")) {
                PrivacySettingsRow(icon: "phone.fill", title: L("at_call_hours"), subtitle: L("at_call_hours_note"), checked: hoursOn) { on in
                    save(["call_hours": on ? callHoursBody("09:00", "22:00", Array(0...6)) : .null])
                }
                if hoursOn {
                    PrivacySettingsRow(icon: "clock", title: L("ch_schedule_time"), value: "\(hours?.from ?? "") – \(hours?.to ?? "")") { sheet = "callHours" }
                    PrivacySettingsRow(icon: "calendar", title: L("ch_weekdays"), value: callDaysLabel(hours?.days ?? [])) { sheet = "callHours" }
                }
                PrivacySettingsRow(icon: "phone.fill", title: L("at_who_can_call")) { host.push(.privacy) }
                PrivacySettingsRow(icon: "moon.fill", title: L("at_quiet_hours"), value: d.quiet?.on == true ? L("at_on_now") : L("ch_off"), last: true) { host.push(.privacyCenter) }
            }
            // On my phone only (156).
            LocalIntelligenceSection()
            // What each context does (151).
            RulesSection(title: L("at_contexts"), sub: L("at_contexts_sub")) {
                let contexts = (d.contexts ?? []).isEmpty ? ["home", "work", "meeting", "travel", "private"].map { ContextDto(key: $0) } : d.contexts!
                ForEach(Array(contexts.enumerated()), id: \.offset) { i, c in
                    PrivacySettingsRow(icon: contextIcon(c.key), title: contextLabel(c.key), value: contextSummary(c), last: i == contexts.count - 1) { host.push(.contextRules(c.key)) }
                }
            }
            Spacer().frame(height: 24)
        }
    }

    @ViewBuilder
    private var sheetView: some View {
        let close = { sheet = nil; askDuration = nil; pickSource = nil }
        if let key = askDuration {
            ChoiceSheet(title: contextLabel(key), subtitle: L("at_for_how_long"), options: [
                (L("ch_hours_n", 1), { setContext(key, 60) }), (L("ch_hours_n", 3), { setContext(key, 180) }),
                (L("at_today"), { setContext(key, minutesToEndOfDay()) }), (L("at_until_change"), { setContext(key, nil) }),
            ], radios: true, onDismiss: close)
        } else if let (src, mode) = pickSource {
            let modes = ["now", "digest", "later"]
            ChoiceSheet(title: sourceLabel(src), options: modes.map { m in
                (modeLabel(m), {
                    var all = (data?.attention ?? [:]).mapValues { JSONValue.string($0) }
                    all[src] = .string(m)
                    save(["attention": .object(all)])
                })
            }, radios: true, selected: max(modes.firstIndex(of: mode) ?? 0, 0), onDismiss: close)
        } else {
            switch sheet {
            case "now":
                let choices: [String?] = [nil, "home", "work", "meeting", "travel", "private"]
                ChoiceSheet(title: L("at_now"), options: choices.map { k in
                    (contextLabel(k), {
                        if let k {
                            Task {
                                try? await Task.sleep(nanoseconds: 350_000_000)
                                askDuration = k
                            }
                        } else { setContext(nil, nil) }
                    })
                }, radios: true, selected: choices.firstIndex(of: data?.context?.key) ?? 0, onDismiss: close)
            case "digest":
                let choices = [15, 30, 60, 120, 240]
                ChoiceSheet(title: L("at_digest_every"), options: choices.map { m in (digestLabel(m), { save(["digest_minutes": .int(m)]) }) },
                            radios: true, selected: choices.firstIndex(of: data?.digestMinutes ?? 60) ?? 0, onDismiss: close)
            case "people":
                PeoplePickerSheet(initial: Set((data?.alwaysThrough ?? []).compactMap { $0.key.hasPrefix("user:") ? Int($0.key.dropFirst(5)) : nil }),
                                  title: "at_through", allowEmpty: true, onDismiss: close) { ids in
                    close()
                    save(["always_through": .array(ids.map { .string("user:\($0)") })])
                }
            case "callHours":
                CallHoursSheet(current: data?.callHours) { from, to, days in
                    close()
                    save(callHoursFields(from, to, days))
                }
            default:
                EmptyView()
            }
        }
    }

    private func save(_ body: [String: JSONValue]) {
        Task {
            do { if let r = try await LifeApi.saveAttention(body).data { data = r } } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
        }
    }

    private func setContext(_ key: String?, _ minutes: Int?) {
        Task {
            do { if let r = try await LifeApi.setContext(key, minutes: minutes).data { data = r } } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
        }
    }

    private func minutesToEndOfDay() -> Int {
        let end = Calendar.current.startOfDay(for: Date().addingTimeInterval(86400))
        return min(max(Int(end.timeIntervalSinceNow / 60), 15), 1440)
    }

    private func callDaysLabel(_ days: [Int]) -> String {
        (days.isEmpty ? Array(0...6) : days.sorted()).map { dayName($0, short: true) }.joined(separator: " · ")
    }
}

private func callHoursBody(_ from: String, _ to: String, _ days: [Int]) -> JSONValue {
    .object(["from": .string(from), "to": .string(to), "days": .array(days.sorted().map { .int($0) }), "timezone": .string(TimeZone.current.identifier)])
}

private func callHoursFields(_ from: String, _ to: String, _ days: [Int]) -> [String: JSONValue] { ["call_hours": callHoursBody(from, to, days)] }

private struct CallHoursSheet: View {
    @ObservedObject private var ch = Ch.shared
    let current: CallHoursDto?
    let onSave: (String, String, [Int]) -> Void
    @State private var from = "09:00"
    @State private var to = "22:00"
    @State private var days: [Int] = Array(0...6)
    /// true: «from», false: «to».
    @State private var picking: Bool?
    @State private var started = false

    var body: some View {
        if let isFrom = picking {
            BusinessTimeSheet(initial: isFrom ? from : to) { t in
                if isFrom { from = t } else { to = t }
                picking = nil
            }
        } else {
            ChSheetCard {
                Text(L("at_call_hours")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
                PrivacySettingsRow(icon: "clock", title: L("ch_from"), value: from) { picking = true }
                PrivacySettingsRow(icon: "clock", title: L("ch_to"), value: to, last: true) { picking = false }
                Text(L("ch_weekdays")).font(.dorr(14, .heavy)).foregroundStyle(ch.ink).padding(.top, 10).padding(.bottom, 8)
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 6), count: 3), spacing: 6) {
                    ForEach(0..<7, id: \.self) { d in
                        let on = days.contains(d)
                        Button {
                            if on { if days.count > 1 { days.removeAll { $0 == d } } } else { days.append(d) }
                        } label: {
                            HStack {
                                Text(verbatim: dayName(d, short: false)).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                Spacer(minLength: 2)
                                Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 19)).foregroundStyle(on ? ch.red : ch.soft)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 11)
                            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
                ChPrimaryButton(text: L("ch_save"), enabled: !days.isEmpty) { onSave(from, to, days) }.padding(.top, 18)
            }
            .onAppear {
                guard !started else { return }
                started = true
                from = current?.from ?? "09:00"
                to = current?.to ?? "22:00"
                days = (current?.days ?? []).isEmpty ? Array(0...6) : current!.days!
            }
        }
    }
}

/// Local private intelligence (spec 156): on this phone only — nothing of it is sent anywhere.
private struct LocalIntelligenceSection: View {
    @ObservedObject private var ch = Ch.shared
    @State private var on = LocalIntelligence.enabled
    @State private var hide = LocalIntelligence.hideSensitive
    @State private var words = LocalIntelligence.keywords
    @State private var trying = ""
    @State private var editWords = false

    var body: some View {
        RulesSection(title: L("lp_title"), sub: L("lp_sub")) {
            PrivacySettingsRow(icon: "iphone", title: L("lp_on"), last: !on, checked: on) { v in
                on = v
                LocalIntelligence.enabled = v
            }
            if on {
                PrivacySettingsRow(icon: "eye.slash.fill", title: L("lp_hide"), subtitle: L("lp_hide_sub"), checked: hide) { v in
                    hide = v
                    LocalIntelligence.hideSensitive = v
                }
                PrivacySettingsRow(icon: "star.fill", title: L("lp_words"), value: String(words.count), last: true) { editWords = true }
                VStack(alignment: .leading, spacing: 6) {
                    Text(L("lp_try")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
                    ChField(placeholder: L("lp_try_hint"), text: $trying, limit: 200)
                    if !trying.trimmingCharacters(in: .whitespaces).isEmpty {
                        let kind = LocalIntelligence.kindOf(trying)
                        let word = words.first { trying.range(of: $0, options: .caseInsensitive) != nil }
                        let parts = [word.map { "⭐ " + L("lp_kind_keyword", $0) }, kind.map { "\($0.emoji) " + L($0.label) }].compactMap { $0 }
                        Text(verbatim: parts.isEmpty ? L("lp_kind_none") : parts.joined(separator: " · ")).font(.dorr(13, .bold)).foregroundStyle(ch.red)
                    }
                }
                .padding(.horizontal, 4).padding(.vertical, 8)
            }
        }
        .dorrDialog(isPresented: $editWords, bottom: true) {
            KeywordsSheet(words: $words) { editWords = false }
        }
    }
}

private struct KeywordsSheet: View {
    @ObservedObject private var ch = Ch.shared
    @Binding var words: [String]
    let onDone: () -> Void
    @State private var adding = ""

    var body: some View {
        ChSheetCard {
            Text(L("lp_words")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            HStack(spacing: 8) {
                ChField(placeholder: L("lp_word_hint"), text: $adding, limit: 40)
                Button {
                    words = Array(Set(words + [adding.trimmingCharacters(in: .whitespaces)])).sorted()
                    LocalIntelligence.keywords = words
                    adding = ""
                } label: {
                    Text(verbatim: "＋").font(.dorr(22, .heavy)).foregroundStyle(ch.red).padding(10)
                }
                .buttonStyle(.plain)
                .disabled(adding.trimmingCharacters(in: .whitespaces).count < 2)
            }
            FlowLayout(spacing: 6) {
                ForEach(words, id: \.self) { w in
                    Button {
                        words.removeAll { $0 == w }
                        LocalIntelligence.keywords = words
                    } label: {
                        HStack(spacing: 6) {
                            Text(verbatim: w).font(.dorr(13, .bold)).foregroundStyle(ch.ink)
                            Image(systemName: "checkmark.square.fill").font(.system(size: 16)).foregroundStyle(ch.red)
                        }
                        .padding(.leading, 12).padding(.trailing, 8).padding(.vertical, 8)
                        .background(ch.red.opacity(0.12), in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 10)
            ChPrimaryButton(text: L("ch_save"), action: onDone).padding(.top, 16)
        }
    }
}

/// What one context does: what notifications show, who may call, and each source's attention.
struct ContextRulesPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let key: String
    @State private var data: AttentionDto?
    @State private var sheet: String?
    @State private var pickSource: (String, String)?

    var body: some View {
        let c = data?.contexts?.first { $0.key == key } ?? ContextDto(key: key)
        let sources = (data?.sources ?? []).isEmpty ? defaultSources : data!.sources!
        ChSettingsPage(title: contextLabel(key), onBack: { host.pop() }) {
            if data == nil {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            } else {
                ScrollView {
                    VStack(spacing: 0) {
                        PrivacySettingsRow(icon: "eye.fill", title: L("at_shows"), value: showsLabel(c.disclosure)) { sheet = "shows" }
                        PrivacySettingsRow(icon: "phone.fill", title: L("at_calls"), value: callsLabel(c.calls)) { sheet = "calls" }
                        ForEach(Array(sources.enumerated()), id: \.offset) { i, src in
                            let mode = c.attention?[src] ?? "now"
                            PrivacySettingsRow(icon: sourceIcon(src), title: sourceLabel(src), value: modeLabel(mode), last: i == sources.count - 1) { pickSource = (src, mode) }
                        }
                    }
                    .padding(.horizontal, 8).padding(.vertical, 4)
                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
                    .padding(16)
                }
            }
        }
        .task(id: key) { data = try? await LifeApi.attention().data }
        .dorrDialog(isPresented: Binding(get: { sheet != nil || pickSource != nil }, set: { if !$0 { sheet = nil; pickSource = nil } }), bottom: true) {
            let close = { sheet = nil; pickSource = nil }
            if let (src, mode) = pickSource {
                let modes = ["now", "digest", "later"]
                ChoiceSheet(title: sourceLabel(src), options: modes.map { m in
                    (modeLabel(m), {
                        var all = (c.attention ?? [:]).mapValues { JSONValue.string($0) }
                        all[src] = .string(m)
                        saveContext(["attention": .object(all)])
                    })
                }, radios: true, selected: modes.firstIndex(of: mode) ?? 0, onDismiss: close)
            } else if sheet == "shows" {
                let choices: [String?] = [nil, "name", "none"]
                ChoiceSheet(title: L("at_shows"), options: choices.map { v in (showsLabel(v), { saveContext(["disclosure": v.map { .string($0) } ?? .null]) }) },
                            radios: true, selected: choices.firstIndex(of: c.disclosure == "all" ? nil : c.disclosure) ?? 0, onDismiss: close)
            } else if sheet == "calls" {
                let choices = ["everyone", "through", "nobody"]
                ChoiceSheet(title: L("at_calls"), options: choices.map { v in (callsLabel(v), { saveContext(["calls": .string(v)]) }) },
                            radios: true, selected: choices.firstIndex(of: c.calls ?? "everyone") ?? 0, onDismiss: close)
            }
        }
    }

    private func saveContext(_ change: [String: JSONValue]) {
        Task {
            do { if let r = try await LifeApi.saveAttention(["contexts": .object([key: .object(change)])]).data { data = r } } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
            }
        }
    }
}
