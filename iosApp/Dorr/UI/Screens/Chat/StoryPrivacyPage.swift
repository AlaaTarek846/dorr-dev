import SwiftUI

// androidApp `chat/StoryPrivacyPage.kt` — who sees my status: all my contacts, my contacts except some, or only some.

struct StoryPrivacyPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var privacy: StoryPrivacyDto?
    @State private var picking: String?

    var body: some View {
        ChPage(title: L("st_privacy"), onBack: { host.pop() }) {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    VStack(spacing: 0) {
                        let p = privacy
                        option("contacts", L("st_audience_contacts"), count: nil)
                        option("except", L("st_audience_except"), count: p?.except?.count)
                        option("only", L("st_audience_only"), count: p?.only?.count)
                    }
                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                    Text(L("st_privacy_note")).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.horizontal, 8)
                }
                .padding(14)
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

    private func option(_ value: String, _ label: String, count: Int?) -> some View {
        let selected = privacy?.audience == value
        return Button {
            if value == "contacts" { save("contacts") } else { picking = value }
        } label: {
            HStack(spacing: 14) {
                ZStack {
                    Circle().stroke(selected ? ch.red : ch.soft, lineWidth: 2).frame(width: 24, height: 24)
                    Circle().fill(ch.red).frame(width: 12, height: 12).scaleEffect(selected ? 1 : 0)
                }
                .animation(.spring(response: 0.3, dampingFraction: 0.4), value: selected)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                    if let count, count > 0 { Text(L("st_people_selected", count)).font(.dorr(12, .semibold)).foregroundStyle(ch.red) }
                }
                Spacer()
            }
            .padding(16)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// Pick people (my contacts on Dorr).
private struct StoryPeoplePicker: View {
    @ObservedObject private var ch = Ch.shared
    let preselected: [Int]
    let onDismiss: () -> Void
    let onDone: ([Int]) -> Void
    @State private var contacts: [ContactDto]?
    @State private var selected: [Int] = []

    var body: some View {
        ChSheetCard {
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("st_choose_people")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    Text(L("st_people_selected", selected.count)).font(.dorr(12.5)).foregroundStyle(ch.mut)
                }
                Spacer()
                ChPrimaryButton(text: L("st_done"), full: false) { onDone(selected) }
            }
            .padding(.bottom, 12)
            if let list = contacts {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(Array(list.filter { $0.profile != nil }.enumerated()), id: \.element.id) { i, c in
                            let id = c.profile?.id ?? 0
                            let on = selected.contains(id)
                            Button {
                                if on { selected.removeAll { $0 == id } } else { selected.append(id) }
                            } label: {
                                HStack(spacing: 12) {
                                    ChAvatar(url: c.profile?.avatar, name: c.name, key: c.profile?.key, size: 44)
                                    Text(verbatim: c.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                                    Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 26)).foregroundStyle(on ? ch.red : ch.soft)
                                        .animation(.spring(response: 0.3, dampingFraction: 0.4), value: on)
                                }
                                .padding(.vertical, 8).padding(.horizontal, 6)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                            .chStagger(i)
                        }
                    }
                }
                .frame(maxHeight: 460)
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(minHeight: 160)
            }
        }
        .onAppear { selected = preselected }
        .task { contacts = (try? await ChatApi.contacts(registered: 1).data) ?? [] }
    }
}
