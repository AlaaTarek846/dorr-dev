import SwiftUI

// androidApp `chat/StoryPrivacyPage.kt` — who sees my status: all my contacts, my contacts except some, or only some.

struct StoryPrivacyPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var privacy: StoryPrivacyDto?
    @State private var picking: String?

    var body: some View {
        // The app's inner-screen look (as «Starred messages»): the soft backdrop and the title with back.
        ChSettingsPage(title: L("st_privacy"), onBack: { host.pop() }) {
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    // First, highlighted: a change applies to new statuses only.
                    HStack(alignment: .top, spacing: 10) {
                        Image(systemName: "info.circle.fill").font(.system(size: 18)).foregroundStyle(ch.red)
                        Text(L("st_privacy_note")).font(.dorr(13)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(14)
                    .background(ch.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(ch.red.opacity(0.25), lineWidth: 1))
                    .padding(.bottom, 2)
                    // The question the choices answer.
                    Text(L("st_privacy_who")).font(.dorr(13, .bold)).foregroundStyle(ch.mut).padding(.horizontal, 6).padding(.top, 8)
                    let p = privacy
                    option("contacts", L("st_audience_contacts"), sub: L("st_audience_contacts_sub"), count: nil)
                    option("except", L("st_audience_except"), sub: L("st_audience_except_sub"), count: p?.except?.count)
                    option("only", L("st_audience_only"), sub: L("st_audience_only_sub"), count: p?.only?.count)
                }
                .padding(.horizontal, 14).padding(.bottom, 14)
            }
        }
        .task { privacy = try? await ChatApi.storyPrivacy().data }
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } }), bottom: true) {
            if let list = picking {
                StoryPeoplePicker(preselected: ((list == "except" ? privacy?.except : privacy?.only) ?? []).map(\.id), onDismiss: { picking = nil }) { ids in
                    picking = nil
                    if list == "except" { save("except", except: ids) } else { save("only", only: ids) }
                }
            }
        }
    }

    private func save(_ audience: String, except: [Int]? = nil, only: [Int]? = nil) {
        var body: [String: Any?] = ["audience": audience]
        if let except { body["except"] = except }
        if let only { body["only"] = only }
        Task { if let p = try? await ChatApi.updateStoryPrivacy(body).data { privacy = p } }
    }

    private func option(_ value: String, _ label: String, sub: String, count: Int?) -> some View {
        let selected = privacy?.audience == value
        return Button {
            if value == "contacts" { save("contacts") } else { picking = value }
        } label: {
            // One choice as its own card: its name and, under it, what it means; the people picked for it beside the
            // name (👤 N); the radio at the other end.
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 8) {
                        Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                        if let count, count > 0 {
                            HStack(spacing: 3) {
                                Image(systemName: "person.fill").font(.system(size: 11))
                                Text(verbatim: "\(count)").font(.dorr(12, .bold))
                            }
                            .foregroundStyle(ch.red)
                            .padding(.horizontal, 7).padding(.vertical, 2)
                            .background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                            .accessibilityElement(children: .ignore)
                            .accessibilityLabel(L("st_people_selected", count))
                        }
                    }
                    Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut)
                }
                Spacer()
                ZStack {
                    Circle().stroke(selected ? ch.red : ch.soft, lineWidth: 2).frame(width: 24, height: 24)
                    Circle().fill(ch.red).frame(width: 12, height: 12).scaleEffect(selected ? 1 : 0)
                }
                .animation(.spring(response: 0.3, dampingFraction: 0.4), value: selected)
            }
            .padding(.horizontal, 16).padding(.vertical, 15)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(selected ? ch.red.opacity(0.45) : .clear, lineWidth: 1.5))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// Pick people (my contacts on Dorr): a search, pages of 15 that load as I scroll (as the other pickers), a tick per
/// person — kept while I search — and «Done» across the bottom (androidApp `PeoplePicker` in StoryPrivacyPage.kt).
private struct StoryPeoplePicker: View {
    @ObservedObject private var ch = Ch.shared
    let preselected: [Int]
    let onDismiss: () -> Void
    let onDone: ([Int]) -> Void
    @State private var query = ""
    @State private var contacts: [ContactDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var selected: [Int] = []
    @State private var started = false

    var body: some View {
        ChSheetCard {
            // The title with its icon · how many are picked, at the other end.
            HStack(spacing: 10) {
                ChIconBadge(icon: "person.2.fill", color: ch.red, size: 38)
                Text(L("st_choose_people")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                Text(L("st_people_selected", selected.count)).font(.dorr(12.5, .bold))
                    .foregroundStyle(selected.isEmpty ? ch.mut : ch.red)
                    .padding(.horizontal, 10).padding(.vertical, 5)
                    .background((selected.isEmpty ? ch.mut : ch.red).opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .padding(.bottom, 12)
            ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass", limit: 80, clearable: true).padding(.bottom, 8)
            if let items = contacts {
                if items.isEmpty {
                    Text(L("ch_no_results")).font(.dorr(13.5)).foregroundStyle(ch.mut).frame(maxWidth: .infinity).frame(height: 160)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            ForEach(Array(items.enumerated()), id: \.element.id) { i, c in
                                // Near the end of the list → the next 15.
                                row(c).chStagger(min(i, 14)).onAppear { if i >= items.count - 3 { Task { await loadPage(reset: false) } } }
                            }
                            if loadingMore { ProgressView().tint(ch.red).padding(10) }
                        }
                    }
                    .frame(height: 380)
                }
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 380)
            }
            // «Done», across the whole width.
            ChPrimaryButton(text: L("st_done")) { onDone(selected) }
                .padding(.top, 12)
        }
        .onAppear {
            if !started { started = true; selected = preselected }
        }
        .task(id: query) {
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 280_000_000) }
            guard !Task.isCancelled else { return }
            await loadPage(reset: true)
        }
    }

    private func row(_ c: ContactDto) -> some View {
        let id = c.profile?.id ?? 0
        let on = selected.contains(id)
        return Button {
            if on { selected.removeAll { $0 == id } } else { selected.append(id) }
        } label: {
            HStack(spacing: 12) {
                ChAvatar(url: c.profile?.avatar, name: c.name, key: c.profile?.key, size: 44)
                Text(verbatim: ltrTitle(c.name)).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 26)).foregroundStyle(on ? ch.red : ch.soft)
                    .animation(.spring(response: 0.3, dampingFraction: 0.4), value: on)
            }
            .padding(.vertical, 8).padding(.horizontal, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func loadPage(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let q = query.trimmingCharacters(in: .whitespaces)
        let envelope = try? await ChatApi.contacts(registered: 1, q: q.isEmpty ? nil : q, page: next, perPage: 15)
        let chunk = (envelope?.data ?? []).filter { $0.profile != nil }
        if reset {
            contacts = chunk
        } else {
            let known = Set((contacts ?? []).map(\.id))
            contacts = (contacts ?? []) + chunk.filter { !known.contains($0.id) }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }
}
