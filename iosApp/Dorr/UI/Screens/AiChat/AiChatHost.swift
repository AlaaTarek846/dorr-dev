import SwiftUI

// androidApp `aichat/AiChatHost.kt` — DORR AI end to end. It opens on the DORR AI home: every service the assistant
// offers, grouped in the order people reach for it. Each screen goes back to the one it was opened from (a small
// back stack); back on the home leaves the assistant. The tab bar's AI button opens it.

enum AiScreen: Hashable {
    case home, list, conversation, voice, subscription, language, sites, world, ar, settings, search, memory, outputs, projects
}

struct AiChatHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var gate = AiPlanGate.shared
    let onExit: () -> Void

    @State private var stack: [AiScreen] = [.home]
    @State private var conversations: [AiConversationDto] = []
    @State private var openConversationId: Int?
    @State private var status: AiStatusDto?
    @State private var loading = true
    @State private var loadError: String?
    @State private var bootstrapped = false
    /// A search or gallery result opens its conversation at that message (spec 138).
    @State private var focusMessageId: Int?
    /// A home starter opens a new chat already saying what to do (and maybe the file picker).
    @State private var starterText: String?
    @State private var starterAttach = false
    @State private var continuing = false

    private var screen: AiScreen { stack.last ?? .home }

    private func go(_ next: AiScreen) { withAnimation(.easeInOut(duration: 0.25)) { stack.append(next) } }

    private func back() {
        if stack.count > 1 { withAnimation(.easeInOut(duration: 0.25)) { _ = stack.removeLast() } } else { onExit() }
    }

    private func openAt(_ id: Int, _ messageId: Int?) {
        openConversationId = id
        focusMessageId = messageId
        starterText = nil
        starterAttach = false
        // Replace a conversation already on top instead of stacking chat on chat.
        withAnimation(.easeInOut(duration: 0.25)) {
            if stack.last == .conversation { stack.removeLast() }
            stack.append(.conversation)
        }
    }

    private func refreshConversations() async {
        do {
            conversations = try await AiChatApi.conversations().data ?? []
            loadError = nil
        } catch {
            loadError = error.apiFailure.message
        }
    }

    private func load() async {
        loading = true
        loadError = nil
        do { status = try await AiChatApi.status().data } catch { loadError = error.apiFailure.message; status = nil }
        if status?.available == true { await refreshConversations() }
        loading = false
    }

    private func startNewChat(_ text: String? = nil, attach: Bool = false) async {
        loading = true
        do {
            if let conv = try await AiChatApi.createConversation().data {
                conversations.insert(conv, at: 0)
                openAt(conv.id, nil)
                starterText = text
                starterAttach = attach
            }
        } catch {
            loadError = error.apiFailure.message
        }
        loading = false
    }

    private func onHomeAction(_ action: AiHomeAction) {
        switch action {
        case .newChat: Task { await startNewChat() }
        case .image: Task { await startNewChat(L("ai_home_prompt_image")) }
        case .video: Task { await startNewChat(L("ai_home_prompt_video")) }
        case .document: Task { await startNewChat(L("ai_home_prompt_document")) }
        case .translate: Task { await startNewChat(L("ai_home_prompt_translate")) }
        case .code: Task { await startNewChat(L("ai_home_prompt_code")) }
        case .analyze: Task { await startNewChat(L("ai_home_prompt_analyze"), attach: true) }
        case .voice: go(.voice)
        case .sites: go(.sites)
        case .world: go(.world)
        case .ar: go(.ar)
        case .history: Task { await refreshConversations() }; go(.list)
        case .search: go(.search)
        case .projects: Task { await refreshConversations() }; go(.projects)
        case .outputs: go(.outputs)
        case .continue: Task { await refreshConversations() }; continuing = true
        case .memory: go(.memory)
        case .language: go(.language)
        case .subscription: go(.subscription)
        case .settings: go(.settings)
        }
    }

    var body: some View {
        ZStack {
            wa.ai.bg.ignoresSafeArea()
            page.id(screen).transition(.opacity.combined(with: .move(edge: .trailing)))
        }
        .task {
            guard !bootstrapped else { return }
            await load()
            bootstrapped = true
            #if DEBUG
            // -DorrAiScreen conversation|list|settings|subscription (with fixtures): straight to that page.
            switch UserDefaults.standard.string(forKey: "DorrAiScreen") {
            case "conversation": openAt(7, nil)
            case "list": go(.list)
            case "settings": go(.settings)
            case "subscription": go(.subscription)
            case "sites": go(.sites)
            case "world": go(.world)
            case "ar": go(.ar)
            default: break
            }
            #endif
        }
        // «Subscribe» on the plan prompt: straight to the subscription page.
        .onChange(of: gate.openSubscription) { open in
            if open && bootstrapped { gate.openSubscription = false; go(.subscription) }
        }
        .onChange(of: bootstrapped) { done in
            if done && gate.openSubscription { gate.openSubscription = false; go(.subscription) }
        }
        .dorrDialog(isPresented: $continuing) {
            AiContinueDialog(conversations: conversations, onDismiss: { continuing = false }) { created in
                continuing = false
                conversations.insert(created, at: 0)
                openAt(created.id, nil)
            }
        }
    }

    @ViewBuilder private var page: some View {
        switch screen {
        case .home:
            AiHomeScreen(status: status, loading: loading, errorMessage: loadError, conversations: conversations, onExit: onExit,
                         onRetry: { Task { await load() } }, onAction: onHomeAction, onOpenConversation: { openAt($0, nil) })
        case .conversation:
            if let id = openConversationId {
                AiConversationPage(
                    conversationId: id,
                    onExit: { Task { await refreshConversations() }; back() },
                    onOpenHistory: { Task { await refreshConversations() }; go(.list) },
                    onNewChat: { Task { await startNewChat() } },
                    onOpenVoice: { go(.voice) },
                    onOpenSubscription: { go(.subscription) },
                    onOpenSettings: { go(.settings) },
                    focusMessageId: focusMessageId,
                    initialInput: starterText,
                    openAttachments: starterAttach
                )
                .id(id)
            }
        case .search:
            AiSearchScreen(onExit: back, onOpen: { openAt($0, $1) })
        case .memory:
            AiMemoryScreen(onExit: back)
        case .outputs:
            AiOutputsScreen(onExit: back, onOpenConversation: { openAt($0, $1) })
        case .projects:
            AiProjectsScreen(conversations: conversations, onExit: back, onOpenConversation: { openAt($0, nil) })
        case .subscription:
            // Coming back, the plan chip and the AI buttons reflect the new plan.
            AiSubscriptionScreen(onExit: { Task { await load() }; back() })
        case .language:
            AiLanguageSettingsScreen(onExit: back)
        case .voice:
            AiVoiceScreen(conversationId: openConversationId, onExit: back)
        case .world:
            AiWorldScreen(onExit: back)
        case .ar:
            AiArScreen(onExit: back)
        case .sites:
            AiSitesScreen(onExit: back)
        case .settings:
            AiSettingsScreen(onExit: back, onOpenSubscription: { go(.subscription) }, onOpenLanguage: { go(.language) }, onOpenSites: { go(.sites) },
                             onOpenWorld: { go(.world) }, onOpenAr: { go(.ar) }, onOpenMemory: { go(.memory) },
                             onOpenProjects: { Task { await refreshConversations() }; go(.projects) }, onOpenOutputs: { go(.outputs) })
        case .list:
            AiConversationListPage(
                loading: loading,
                unavailable: status != nil && status?.available == false,
                brandName: status?.brandName ?? "DORR AI",
                errorMessage: loadError,
                conversations: conversations,
                onBack: back,
                onRetry: { Task { await refreshConversations() } },
                onOpen: { openAt($0, nil) },
                onNewChat: { Task { await startNewChat() } },
                onOpenSettings: { go(.settings) },
                onDelete: { id in
                    Task {
                        _ = try? await AiChatApi.deleteConversation(id)
                        conversations.removeAll { $0.id == id }
                        if openConversationId == id { openConversationId = conversations.first?.id }
                    }
                },
                onOpenSearch: { go(.search) },
                onContinue: { continuing = true }
            )
        }
    }
}
