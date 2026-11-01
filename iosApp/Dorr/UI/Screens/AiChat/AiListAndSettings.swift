import SwiftUI

// androidApp `aichat/AiConversationListPage.kt` and `AiSettingsScreen.kt` — all my DORR AI chats (open, delete, search,
// continue, a new one), and the one settings hub (plan, language, memory, projects, creations, sites, World, AR).

struct AiConversationListPage: View {
    @Environment(\.wa) private var wa
    let loading: Bool
    let unavailable: Bool
    let brandName: String
    let errorMessage: String?
    let conversations: [AiConversationDto]
    let onBack: () -> Void
    let onRetry: () -> Void
    let onOpen: (Int) -> Void
    let onNewChat: () -> Void
    let onOpenSettings: () -> Void
    let onDelete: (Int) -> Void
    let onOpenSearch: () -> Void
    let onContinue: () -> Void
    @State private var confirmDelete: Int?

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onBack)
                Text(L("ai_history_title")).font(.dorr(18, .heavy)).foregroundStyle(c.ink).frame(maxWidth: .infinity, alignment: .leading).padding(.leading, 4)
                if !unavailable {
                    // Search by word and by meaning (spec 134); continue earlier chats in a new one (spec 123).
                    AiRoundIcon(icon: "magnifyingglass", tint: c.mut, action: onOpenSearch).accessibilityLabel(L("ai_tools_search_title"))
                    if !conversations.isEmpty { AiRoundIcon(icon: "clock.arrow.circlepath", tint: c.mut, size: 18, action: onContinue).accessibilityLabel(L("ai_tools_continue")) }
                    AiRoundIcon(icon: "gearshape.fill", tint: c.mut, size: 18, action: onOpenSettings).accessibilityLabel(L("ai_settings_title"))
                    Button(action: onNewChat) {
                        Image(systemName: "plus").font(.system(size: 17, weight: .semibold)).foregroundStyle(c.red).frame(width: 38, height: 38).background(c.red.opacity(0.12), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("ai_new_chat"))
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 10)

            if unavailable {
                notice(L("ai_unavailable", brandName))
            } else if loading && conversations.isEmpty {
                ProgressView().tint(c.red).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if let errorMessage, conversations.isEmpty {
                notice(errorMessage, retry: true)
            } else if conversations.isEmpty {
                notice(L("ai_empty_history"))
            } else {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(conversations) { conv in row(conv) }
                    }
                    .padding(.top, 4)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .dorrDialog(isPresented: Binding(get: { confirmDelete != nil }, set: { if !$0 { confirmDelete = nil } })) {
            AiConfirmDialog(title: L("ai_delete_chat"), message: L("ai_delete_chat_confirm"), onConfirm: {
                if let id = confirmDelete { onDelete(id) }
                confirmDelete = nil
            }, onDismiss: { confirmDelete = nil })
        }
    }

    private func row(_ conv: AiConversationDto) -> some View {
        let c = wa.ai
        return HStack(spacing: 12) {
            Button { onOpen(conv.id) } label: {
                HStack(spacing: 12) {
                    Image(systemName: "sparkles").font(.system(size: 18)).foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                        .background(LinearGradient(colors: [Color(hex: 0xFF8A65), c.red], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: conv.title?.isEmpty == false ? conv.title! : L("ai_new_chat")).font(.dorr(15, .bold)).foregroundStyle(c.ink).lineLimit(1)
                        if let p = conv.lastMessagePreview, !p.isEmpty { Text(verbatim: p).font(.dorr(13)).foregroundStyle(c.mut).lineLimit(1) }
                    }
                    Spacer(minLength: 8)
                    Text(verbatim: aiTimeAgo(conv.updatedAt ?? conv.createdAt)).font(.dorr(11)).foregroundStyle(c.mut)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            AiRoundIcon(icon: "trash", tint: c.mut, size: 15) { confirmDelete = conv.id }.accessibilityLabel(L("ai_delete_chat"))
        }
        .padding(.leading, 16).padding(.trailing, 8).padding(.vertical, 8)
    }

    private func notice(_ text: String, retry: Bool = false) -> some View {
        let c = wa.ai
        return VStack(spacing: 12) {
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(c.mut).multilineTextAlignment(.center)
            if retry {
                Button(action: onRetry) {
                    Text(L("ai_retry")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 20).padding(.vertical, 10)
                        .background(c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

struct AiSettingsScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    let onOpenSubscription: () -> Void
    let onOpenLanguage: () -> Void
    let onOpenSites: () -> Void
    let onOpenWorld: () -> Void
    let onOpenAr: () -> Void
    let onOpenMemory: () -> Void
    let onOpenProjects: () -> Void
    let onOpenOutputs: () -> Void

    var body: some View {
        let c = wa.ai
        let entries: [(String, String, String, () -> Void)] = [
            ("crown.fill", L("ai_subscription_manage"), L("ai_settings_subscription_sub"), onOpenSubscription),
            ("character.bubble.fill", L("ai_language_title"), L("ai_settings_language_sub"), onOpenLanguage),
            ("brain.head.profile", L("ai_tools_memory_title"), L("ai_tools_memory_sub"), onOpenMemory),
            ("folder.fill", L("ai_tools_projects_title"), L("ai_tools_projects_sub"), onOpenProjects),
            ("square.stack.fill", L("ai_tools_outputs_title"), L("ai_tools_outputs_sub"), onOpenOutputs),
            ("globe.desk.fill", L("ai_sites_title"), L("ai_sites_settings_sub"), onOpenSites),
            ("globe.europe.africa.fill", L("ai_world_title"), L("ai_world_settings_sub"), onOpenWorld),
            ("arkit", L("ai_ar_title"), L("ai_ar_settings_sub"), onOpenAr),
        ]
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onExit)
                Text(L("ai_settings_title")).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
                Spacer()
            }
            .padding(.horizontal, 8).padding(.vertical, 8)
            ScrollView {
                VStack(spacing: 8) {
                    ForEach(entries, id: \.1) { icon, title, sub, action in
                        Button(action: action) {
                            HStack(spacing: 12) {
                                Image(systemName: icon).font(.system(size: 18)).foregroundStyle(c.red)
                                    .frame(width: 40, height: 40).background(c.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                                    Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(c.mut).multilineTextAlignment(.leading)
                                }
                                Spacer(minLength: 0)
                                Image(systemName: "chevron.right").font(.system(size: 13, weight: .semibold)).foregroundStyle(c.mut).flipsForRightToLeftLayoutDirection(true)
                            }
                            .padding(.horizontal, 14).padding(.vertical, 12)
                            .background(c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .shadow(color: c.red.opacity(c.night ? 0 : 0.08), radius: 5, y: 2)
                        }
                        .buttonStyle(AiPressStyle())
                    }
                }
                .padding(.horizontal, 16).padding(.top, 6).padding(.bottom, 32)
            }
        }
        .background(c.bg.ignoresSafeArea())
    }
}
