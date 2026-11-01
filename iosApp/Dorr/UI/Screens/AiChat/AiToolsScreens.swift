import SwiftUI

// androidApp `aichat/AiAssistantToolsScreens.kt` — DORR AI's own tools, each one screen inside AiChatHost: search
// the history by word and by meaning (spec 133–139), memory (140–144), the outputs gallery (344), projects (151) and
// «continue in a new chat» with the carried-over context it shows in the conversation (121–132).

private struct AiToolHeader<Trailing: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void
    @ViewBuilder var trailing: () -> Trailing

    var body: some View {
        let c = wa.ai
        HStack(spacing: 8) {
            AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onBack)
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(c.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
            trailing()
        }
        .padding(.horizontal, 8).padding(.vertical, 8)
    }
}

extension AiToolHeader where Trailing == EmptyView {
    init(title: String, onBack: @escaping () -> Void) { self.init(title: title, onBack: onBack, trailing: { EmptyView() }) }
}

private struct AiToolNotice: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(wa.ai.mut).multilineTextAlignment(.center).padding(32).frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

private struct AiToolLoading: View {
    @Environment(\.wa) private var wa
    var body: some View { ProgressView().tint(wa.ai.red).frame(maxWidth: .infinity, maxHeight: .infinity) }
}

/// The outlined field of the tools (Android's OutlinedTextField with the AI colours).
private struct AiField: View {
    @Environment(\.wa) private var wa
    let placeholder: String
    @Binding var text: String
    var limit = 500
    var lines: ClosedRange<Int> = 1...1
    var icon: String?

    var body: some View {
        let c = wa.ai
        HStack(alignment: .top, spacing: 8) {
            if let icon { Image(systemName: icon).foregroundStyle(c.mut).padding(.top, 2) }
            TextField(placeholder, text: Binding(get: { text }, set: { text = String($0.prefix(limit)) }), axis: .vertical)
                .lineLimit(lines).font(.dorr(14)).foregroundStyle(c.ink).tint(c.red)
        }
        .padding(.horizontal, 14).padding(.vertical, 13)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(c.line, lineWidth: 1))
    }
}

struct AiChip: View {
    @Environment(\.wa) private var wa
    let text: String
    var selected = false
    var onClick: (() -> Void)?

    var body: some View {
        let c = wa.ai
        let label = Text(verbatim: text).font(.dorr(11, .semibold)).foregroundStyle(selected ? .white : c.red)
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(selected ? c.red : c.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
        if let onClick { Button(action: onClick) { label }.buttonStyle(.plain) } else { label }
    }
}

private let aiDanger = Color(hex: 0xDC2626)
private let aiErrorInk = Color(hex: 0xB91C1C)

// MARK: - Search (spec 133–139)

struct AiSearchScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    let onOpen: (Int, Int?) -> Void
    @State private var query = ""
    @State private var results: [AiSearchHitDto]?
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            AiToolHeader(title: L("ai_tools_search_title"), onBack: onExit)
            AiField(placeholder: L("ai_tools_search_hint"), text: $query, limit: 100, icon: "magnifyingglass").padding(.horizontal, 16).padding(.vertical, 4)
            if loading && results == nil {
                AiToolLoading()
            } else if let error {
                AiToolNotice(text: error)
            } else if results?.isEmpty == true {
                AiToolNotice(text: L("ai_tools_search_empty"))
            } else {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(Array((results ?? []).enumerated()), id: \.offset) { _, hit in hitRow(hit) }
                    }
                    .padding(.vertical, 8)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        // Typing pauses a moment before searching — each search may ask the AI what the words mean.
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { results = nil; return }
            try? await Task.sleep(nanoseconds: 450_000_000)
            guard !Task.isCancelled else { return }
            loading = true
            error = nil
            do { results = try await AiAssistantApi.search(q).data?.results ?? [] } catch { if !Task.isCancelled { self.error = error.apiFailure.message } }
            loading = false
        }
    }

    private func hitRow(_ hit: AiSearchHitDto) -> some View {
        let c = wa.ai
        let where_: String? = {
            switch hit.type {
            case "title": return L("ai_tools_search_in_title")
            case "file": return L("ai_tools_search_in_file")
            case "project": return L("ai_tools_search_in_project", hit.project?.name ?? "")
            default: return nil
            }
        }()
        return Button { onOpen(hit.conversation.id, hit.messageId) } label: {
            VStack(alignment: .leading, spacing: 3) {
                HStack {
                    Text(verbatim: hit.conversation.title?.isEmpty == false ? hit.conversation.title! : L("ai_new_chat")).font(.dorr(14, .bold)).foregroundStyle(c.ink).lineLimit(1)
                    Spacer()
                    Text(verbatim: aiTimeAgo(hit.createdAt)).font(.dorr(11)).foregroundStyle(c.mut)
                }
                Text(highlighted(hit)).font(.dorr(13)).foregroundStyle(c.ink).lineLimit(3).multilineTextAlignment(.leading)
                HStack(spacing: 6) {
                    if hit.match == "semantic" { AiChip(text: L("ai_tools_search_by_meaning", hit.term ?? "")) }
                    if let where_ { AiChip(text: where_) }
                }
                .padding(.top, 1)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    /// The matched word, highlighted where the server says it is (spec 136).
    private func highlighted(_ hit: AiSearchHitDto) -> AttributedString {
        let snippet = hit.snippet ?? ""
        var out = AttributedString(snippet)
        let chars = Array(snippet)
        for h in hit.highlights ?? [] {
            let end = min(h.start + h.length, chars.count)
            guard h.start >= 0, h.start < end else { continue }
            let lower = out.index(out.startIndex, offsetByCharacters: h.start)
            let upper = out.index(out.startIndex, offsetByCharacters: end)
            out[lower..<upper].backgroundColor = wa.ai.red.opacity(0.22)
            out[lower..<upper].font = .dorr(13, .bold)
        }
        return out
    }
}

// MARK: - Memory (spec 140–144)

struct AiMemoryScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var enabled = true
    @State private var memories: [AiMemoryDto] = []
    @State private var loading = true
    @State private var error: String?
    @State private var draft = ""
    @State private var editing: AiMemoryDto?
    @State private var confirmDeleteAll = false

    private func save() {
        let text = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }
        let target = editing
        Task {
            error = nil
            do {
                let saved = target == nil ? try await AiAssistantApi.addMemory(text).data : try await AiAssistantApi.updateMemory(target!.id, text).data
                if let saved {
                    if let i = memories.firstIndex(where: { $0.id == saved.id }) { memories[i] = saved } else { memories.insert(saved, at: 0) }
                }
                draft = ""
                editing = nil
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            AiToolHeader(title: L("ai_tools_memory_title"), onBack: onExit) {
                if !memories.isEmpty {
                    Button { confirmDeleteAll = true } label: { Text(L("ai_tools_memory_delete_all")).font(.dorr(12.5, .bold)).foregroundStyle(aiDanger).padding(8) }.buttonStyle(.plain)
                }
            }
            // Memory on / off (spec 143).
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ai_tools_memory_toggle")).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                    Text(L("ai_tools_memory_toggle_sub")).font(.dorr(11.5)).foregroundStyle(c.mut)
                }
                Spacer()
                Toggle("", isOn: Binding(get: { enabled }, set: { next in
                    enabled = next
                    Task { do { _ = try await AiAssistantApi.memorySettings(enabled: next) } catch { enabled = !next; self.error = error.apiFailure.message } }
                }))
                .labelsHidden().tint(c.red)
            }
            .padding(14)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.horizontal, 16).padding(.vertical, 4)
            if enabled {
                HStack(spacing: 8) {
                    AiField(placeholder: L("ai_tools_memory_hint"), text: $draft, limit: 500, lines: 1...4)
                    Button(action: save) {
                        Text(L(editing == nil ? "ai_tools_add" : "ai_tools_save")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 12)
                            .background(c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 16).padding(.vertical, 6)
            }
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(aiErrorInk).frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 20).padding(.vertical, 2) }
            if loading {
                AiToolLoading()
            } else if memories.isEmpty {
                AiToolNotice(text: L("ai_tools_memory_empty"))
            } else {
                ScrollView {
                    LazyVStack(spacing: 8) {
                        ForEach(memories) { m in
                            HStack(spacing: 0) {
                                Text(verbatim: m.content).font(.dorr(13.5)).foregroundStyle(c.ink).frame(maxWidth: .infinity, alignment: .leading)
                                AiRoundIcon(icon: "pencil", tint: c.mut, size: 15) { editing = m; draft = m.content }.accessibilityLabel(L("ai_tools_edit"))
                                AiRoundIcon(icon: "trash", tint: c.mut, size: 15) {
                                    Task { if (try? await AiAssistantApi.deleteMemory(m.id)) != nil { memories.removeAll { $0.id == m.id } } }
                                }
                                .accessibilityLabel(L("ai_delete"))
                            }
                            .padding(.leading, 14).padding(.trailing, 4).padding(.vertical, 4)
                            .background(c.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                    }
                    .padding(.horizontal, 16).padding(.vertical, 6)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .task {
            do {
                if let m = try await AiAssistantApi.memories().data {
                    enabled = m.enabled ?? true
                    memories = m.memories ?? []
                }
            } catch {
                self.error = error.apiFailure.message
            }
            loading = false
        }
        .dorrDialog(isPresented: $confirmDeleteAll) {
            AiConfirmDialog(title: L("ai_tools_memory_delete_all"), message: L("ai_tools_memory_delete_all_confirm"), onConfirm: {
                confirmDeleteAll = false
                Task { if (try? await AiAssistantApi.deleteAllMemories()) != nil { memories = [] } }
            }, onDismiss: { confirmDeleteAll = false })
        }
    }
}

// MARK: - Outputs gallery (spec 344)

struct AiOutputsScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    let onOpenConversation: (Int, Int?) -> Void
    @State private var kind: String?
    @State private var items: [AiOutputDto]?
    @State private var error: String?

    var body: some View {
        let c = wa.ai
        let kinds: [(String?, String)] = [(nil, "ai_tools_kind_all"), ("image", "ai_tools_kind_image"), ("video", "ai_tools_kind_video"), ("audio", "ai_tools_kind_audio"), ("document", "ai_tools_kind_document")]
        VStack(spacing: 0) {
            AiToolHeader(title: L("ai_tools_outputs_title"), onBack: onExit)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(kinds, id: \.1) { value, label in AiChip(text: L(label), selected: kind == value) { kind = value } }
                }
                .padding(.horizontal, 16).padding(.vertical, 4)
            }
            if let error {
                AiToolNotice(text: error)
            } else if items == nil {
                AiToolLoading()
            } else if items?.isEmpty == true {
                AiToolNotice(text: L("ai_tools_outputs_empty"))
            } else {
                ScrollView {
                    LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 10) {
                        ForEach(Array((items ?? []).enumerated()), id: \.offset) { _, o in tile(o) }
                    }
                    .padding(12)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .task(id: kind) {
            error = nil
            do { items = try await AiAssistantApi.outputs(kind: kind).data ?? [] } catch { self.error = error.apiFailure.message }
        }
    }

    private func tile(_ o: AiOutputDto) -> some View {
        let c = wa.ai
        return VStack(alignment: .leading, spacing: 0) {
            Button { if let u = o.url.flatMap({ ApiClient.mediaUrl($0) }) { UIApplication.shared.open(u) } } label: {
                ZStack {
                    c.red.opacity(0.06)
                    if o.kind == "image", o.url != nil {
                        RemoteImage(url: o.url)
                    } else {
                        Image(systemName: o.kind == "video" ? "film.fill" : o.kind == "audio" ? "waveform" : "doc.text.fill").font(.system(size: 32)).foregroundStyle(c.red)
                    }
                }
                .aspectRatio(1, contentMode: .fit)
                .clipped()
            }
            .buttonStyle(.plain)
            Text(verbatim: o.name ?? "").font(.dorr(12)).foregroundStyle(c.ink).lineLimit(1).padding(.horizontal, 8).padding(.vertical, 6)
            if let conv = o.conversation {
                Button { onOpenConversation(conv.id, o.messageId) } label: {
                    Text(verbatim: conv.title?.isEmpty == false ? conv.title! : L("ai_new_chat")).font(.dorr(11)).foregroundStyle(c.red).lineLimit(1)
                        .padding(.horizontal, 8).padding(.bottom, 8)
                }
                .buttonStyle(.plain)
            }
        }
        .background(c.surface)
        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

// MARK: - Projects (spec 151)

struct AiProjectsScreen: View {
    @Environment(\.wa) private var wa
    let conversations: [AiConversationDto]
    let onExit: () -> Void
    let onOpenConversation: (Int) -> Void
    @State private var projects: [AiProjectDto]?
    @State private var open: AiProjectDto?
    @State private var editing: AiProjectDto?
    @State private var creating = false
    @State private var picking = false
    @State private var error: String?

    private func reload() async {
        do { projects = try await AiAssistantApi.projects().data ?? [] } catch { self.error = error.apiFailure.message }
    }

    private func reopen(_ id: Int) async {
        if let p = try? await AiAssistantApi.project(id).data { open = p }
    }

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            AiToolHeader(title: open?.name ?? L("ai_tools_projects_title"), onBack: { if open != nil { open = nil } else { onExit() } }) {
                Button { if let open { editing = open } else { creating = true } } label: {
                    Image(systemName: open != nil ? "pencil" : "plus").font(.system(size: 16, weight: .semibold)).foregroundStyle(c.red)
                        .frame(width: 38, height: 38).background(c.red.opacity(0.12), in: Circle())
                }
                .buttonStyle(.plain)
            }
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(aiErrorInk).frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 20) }
            if let current = open {
                project(current)
            } else if projects == nil {
                AiToolLoading()
            } else if projects?.isEmpty == true {
                AiToolNotice(text: L("ai_tools_projects_empty"))
            } else {
                ScrollView {
                    LazyVStack(spacing: 8) {
                        ForEach(projects ?? []) { p in
                            Button { Task { await reopen(p.id) } } label: {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(verbatim: p.name).font(.dorr(14.5, .bold)).foregroundStyle(c.ink)
                                    if let d = p.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(c.mut).lineLimit(2).multilineTextAlignment(.leading) }
                                    Text(verbatim: L("ai_tools_project_chats", p.conversationsCount ?? 0)).font(.dorr(11.5)).foregroundStyle(c.red).padding(.top, 2)
                                }
                                .padding(14)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .background(c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            }
                            .buttonStyle(AiPressStyle())
                        }
                    }
                    .padding(.horizontal, 16).padding(.vertical, 6)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .task { await reload() }
        .dorrDialog(isPresented: Binding(get: { creating || editing != nil }, set: { if !$0 { creating = false; editing = nil } })) {
            let target = editing
            AiProjectDialog(project: target, onDismiss: { creating = false; editing = nil }, onDelete: target.map { t in {
                editing = nil
                open = nil
                Task { _ = try? await AiAssistantApi.deleteProject(t.id); await reload() }
            } }, onSave: { name, description, instructions in
                Task {
                    do {
                        let saved = target == nil
                            ? try await AiAssistantApi.createProject(name: name, description: description, instructions: instructions).data
                            : try await AiAssistantApi.updateProject(target!.id, name: name, description: description, instructions: instructions).data
                        creating = false
                        editing = nil
                        if target != nil { open = saved }
                        await reload()
                    } catch {
                        self.error = error.apiFailure.message
                    }
                }
            })
        }
        .dorrDialog(isPresented: $picking) {
            if let current = open {
                let inProject = Set((current.conversations ?? []).map(\.id))
                AiConversationPicker(title: L("ai_tools_project_add_chat"), conversations: conversations.filter { !inProject.contains($0.id) }, max: 1,
                                     confirmLabel: L("ai_tools_add"), onDismiss: { picking = false }) { ids in
                    picking = false
                    Task {
                        for id in ids { _ = try? await AiAssistantApi.addProjectConversation(current.id, conversation: id) }
                        await reopen(current.id)
                    }
                }
            }
        }
    }

    /// One project: its instructions and conversations; add any of the person's chats.
    private func project(_ current: AiProjectDto) -> some View {
        let c = wa.ai
        return ScrollView {
            LazyVStack(alignment: .leading, spacing: 6) {
                if let text = current.instructions, !text.isEmpty {
                    Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(c.mut).padding(12).frame(maxWidth: .infinity, alignment: .leading)
                        .background(c.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                ForEach(current.conversations ?? []) { conv in
                    HStack {
                        Button { onOpenConversation(conv.id) } label: {
                            Text(verbatim: conv.title?.isEmpty == false ? conv.title! : L("ai_new_chat")).font(.dorr(13.5)).foregroundStyle(c.ink).lineLimit(1)
                                .frame(maxWidth: .infinity, alignment: .leading).contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        AiRoundIcon(icon: "xmark", tint: c.mut, size: 13) {
                            Task { _ = try? await AiAssistantApi.removeProjectConversation(current.id, conversation: conv.id); await reopen(current.id) }
                        }
                    }
                    .padding(.leading, 14).padding(.vertical, 2)
                    .background(c.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                Button { picking = true } label: { Text(L("ai_tools_project_add_chat")).font(.dorr(13, .bold)).foregroundStyle(c.red).padding(8) }
                    .buttonStyle(.plain).padding(.vertical, 6)
            }
            .padding(.horizontal, 16).padding(.vertical, 6)
        }
    }
}

private struct AiProjectDialog: View {
    @Environment(\.wa) private var wa
    let project: AiProjectDto?
    let onDismiss: () -> Void
    let onDelete: (() -> Void)?
    let onSave: (String, String?, String?) -> Void
    @State private var name: String
    @State private var description: String
    @State private var instructions: String
    @State private var confirmDelete = false

    init(project: AiProjectDto?, onDismiss: @escaping () -> Void, onDelete: (() -> Void)?, onSave: @escaping (String, String?, String?) -> Void) {
        self.project = project
        self.onDismiss = onDismiss
        self.onDelete = onDelete
        self.onSave = onSave
        _name = State(initialValue: project?.name ?? "")
        _description = State(initialValue: project?.description ?? "")
        _instructions = State(initialValue: project?.instructions ?? "")
    }

    var body: some View {
        let c = wa.ai
        let blank = name.trimmingCharacters(in: .whitespaces).isEmpty
        VStack(alignment: .leading, spacing: 8) {
            if confirmDelete, let onDelete {
                Text(verbatim: project?.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(c.ink)
                Text(L("ai_tools_project_delete_confirm")).font(.dorr(13.5)).foregroundStyle(c.mut)
                HStack {
                    Spacer()
                    Button { confirmDelete = false } label: { Text(L("ai_cancel")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(10) }.buttonStyle(.plain)
                    Button(action: onDelete) {
                        Text(L("ai_delete")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 8)
                            .background(aiDanger, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.top, 10)
            } else {
                Text(verbatim: project?.name ?? L("ai_tools_project_new")).font(.dorr(16, .heavy)).foregroundStyle(c.ink).padding(.bottom, 2)
                AiField(placeholder: L("ai_tools_project_name"), text: $name, limit: 120)
                AiField(placeholder: L("ai_tools_project_description"), text: $description, limit: 2000, lines: 3...5)
                AiField(placeholder: L("ai_tools_project_instructions"), text: $instructions, limit: 4000, lines: 4...7)
                HStack(spacing: 4) {
                    if onDelete != nil { AiRoundIcon(icon: "trash", tint: aiDanger, size: 16) { confirmDelete = true }.accessibilityLabel(L("ai_delete")) }
                    Spacer()
                    Button(action: onDismiss) { Text(L("ai_cancel")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(10) }.buttonStyle(.plain)
                    Button {
                        func clean(_ s: String) -> String? { let t = s.trimmingCharacters(in: .whitespacesAndNewlines); return t.isEmpty ? nil : t }
                        onSave(name.trimmingCharacters(in: .whitespaces), clean(description), clean(instructions))
                    } label: {
                        Text(L("ai_tools_save")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 16).padding(.vertical, 9)
                            .background(blank ? c.red.opacity(0.4) : c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .disabled(blank)
                }
                .padding(.top, 6)
            }
        }
        .padding(18)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

// MARK: - Continue in a new chat (spec 121–132)

/// Picks one or more of the person's conversations, up to `max`.
struct AiConversationPicker: View {
    @Environment(\.wa) private var wa
    let title: String
    let conversations: [AiConversationDto]
    let max: Int
    let confirmLabel: String
    var busy = false
    var error: String?
    let onDismiss: () -> Void
    let onConfirm: ([Int]) -> Void
    @State private var picked: [Int] = []

    var body: some View {
        let c = wa.ai
        VStack(alignment: .leading, spacing: 8) {
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
            ScrollView {
                VStack(spacing: 0) {
                    ForEach(conversations) { conv in
                        let checked = picked.contains(conv.id)
                        Button {
                            if checked { picked.removeAll { $0 == conv.id } } else if max == 1 { picked = [conv.id] } else if picked.count < max { picked.append(conv.id) }
                        } label: {
                            HStack(spacing: 10) {
                                Image(systemName: checked ? "checkmark.square.fill" : "square").font(.system(size: 19)).foregroundStyle(checked ? c.red : c.mut)
                                Text(verbatim: conv.title?.isEmpty == false ? conv.title! : L("ai_new_chat")).font(.dorr(13.5)).foregroundStyle(c.ink).lineLimit(1)
                                Spacer(minLength: 0)
                            }
                            .padding(.vertical, 9)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .frame(maxHeight: 360)
            .fixedSize(horizontal: false, vertical: true)
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(aiErrorInk) }
            HStack {
                Spacer()
                Button(action: onDismiss) { Text(L("ai_cancel")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(10) }.buttonStyle(.plain)
                Button { onConfirm(picked) } label: {
                    ZStack {
                        if busy { ProgressView().tint(.white).scaleEffect(0.7) } else { Text(verbatim: confirmLabel).font(.dorr(13, .bold)).foregroundStyle(.white) }
                    }
                    .padding(.horizontal, 16).padding(.vertical, 9)
                    .background(picked.isEmpty || busy ? c.red.opacity(0.4) : c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(picked.isEmpty || busy)
            }
            .padding(.top, 4)
        }
        .padding(18)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

/// «Continue in a new chat»: pick the chats, DORR AI builds the capsule, the new chat opens.
struct AiContinueDialog: View {
    let conversations: [AiConversationDto]
    let onDismiss: () -> Void
    let onStarted: (AiConversationDto) -> Void
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        AiConversationPicker(title: L("ai_tools_continue_pick"), conversations: conversations, max: 5, confirmLabel: L("ai_tools_continue_go"),
                             busy: busy, error: error, onDismiss: { if !busy { onDismiss() } }) { ids in
            busy = true
            error = nil
            Task {
                do { if let r = try await AiAssistantApi.continueConversations(ids).data { onStarted(r.conversation) } } catch { self.error = error.apiFailure.message }
                busy = false
            }
        }
    }
}

/// Above a conversation that carries context from earlier chats: where it came from, the full capsule on «View»,
/// and a way to remove it (spec 130). Nothing for any other conversation.
struct AiCapsuleBanner: View {
    @Environment(\.wa) private var wa
    let conversationId: Int
    @State private var capsule: AiCapsuleDto?
    @State private var showing = false
    @State private var confirmDelete = false

    var body: some View {
        let c = wa.ai
        Group {
            if let current = capsule {
                let from = (current.sources ?? []).compactMap { $0.title?.isEmpty == false ? $0.title : nil }.joined(separator: "، ")
                Button { showing = true } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "clock.arrow.circlepath").font(.system(size: 14))
                        Text(verbatim: L("ai_tools_capsule_banner", from)).font(.dorr(12)).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                        Text(L("ai_tools_capsule_view")).font(.dorr(12, .bold))
                    }
                    .foregroundStyle(c.red)
                    .padding(.horizontal, 16).padding(.vertical, 8)
                    .background(c.red.opacity(0.08))
                }
                .buttonStyle(.plain)
            }
        }
        .task(id: conversationId) { capsule = try? await AiAssistantApi.capsule(conversationId).data?.capsule }
        .dorrDialog(isPresented: $showing) {
            if let current = capsule { capsuleCard(current) }
        }
    }

    private func capsuleCard(_ current: AiCapsuleDto) -> some View {
        let c = wa.ai
        return VStack(alignment: .leading, spacing: 0) {
            if confirmDelete {
                Text(L("ai_tools_capsule_delete")).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
                Text(L("ai_tools_capsule_delete_confirm")).font(.dorr(13.5)).foregroundStyle(c.mut).padding(.top, 8)
                HStack {
                    Spacer()
                    Button { confirmDelete = false } label: { Text(L("ai_cancel")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(10) }.buttonStyle(.plain)
                    Button {
                        confirmDelete = false
                        showing = false
                        Task { if (try? await AiAssistantApi.deleteCapsule(conversationId)) != nil { capsule = nil } }
                    } label: {
                        Text(L("ai_delete")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 8)
                            .background(aiDanger, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.top, 18)
            } else {
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("ai_tools_capsule_title")).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
                        if let goal = current.goal, !goal.isEmpty { section(L("ai_tools_capsule_goal"), [goal]) }
                        section(L("ai_tools_capsule_decisions"), current.decisions ?? [])
                        section(L("ai_tools_capsule_facts"), current.facts ?? [])
                        section(L("ai_tools_capsule_files"), current.files ?? [])
                        section(L("ai_tools_capsule_open"), current.openTasks ?? [])
                        section(L("ai_tools_capsule_done"), current.done ?? [])
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .frame(maxHeight: 440)
                .fixedSize(horizontal: false, vertical: true)
                HStack {
                    Button { confirmDelete = true } label: { Text(L("ai_tools_capsule_delete")).font(.dorr(13, .bold)).foregroundStyle(aiDanger).padding(8) }.buttonStyle(.plain)
                    Spacer()
                    Button { showing = false } label: { Text(L("ai_tools_close")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(8) }.buttonStyle(.plain)
                }
                .padding(.top, 14)
            }
        }
        .padding(18)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }

    @ViewBuilder private func section(_ title: String, _ items: [String]) -> some View {
        if !items.isEmpty {
            let c = wa.ai
            Text(verbatim: title).font(.dorr(12.5, .bold)).foregroundStyle(c.red).padding(.top, 12).padding(.bottom, 2)
            ForEach(Array(items.enumerated()), id: \.offset) { _, it in
                Text(verbatim: "• \(it)").font(.dorr(13)).foregroundStyle(c.ink).padding(.vertical, 1)
            }
        }
    }
}
