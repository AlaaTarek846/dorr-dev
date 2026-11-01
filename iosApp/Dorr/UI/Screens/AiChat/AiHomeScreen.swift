import SwiftUI

// androidApp `aichat/AiHomeScreen.kt` — the DORR AI home (spec 147–152, 310): what the assistant offers, at a glance,
// in the order a person reaches for it — ask (the hero, a voice call, one-tap starters), continue (the latest chats),
// create (the studio), your work, make it yours. The plan chip says where the person stands without starting
// anything; when DORR AI isn't available here, everything stays visible but says so.

/// Everything the DORR AI home can open — AiChatHost decides what each one does.
enum AiHomeAction: Hashable {
    case newChat, voice, image, video, document, translate, code, analyze
    case sites, world, ar
    case history, search, projects, outputs, `continue`
    case memory, language, subscription, settings
}

/// One service on the home: what it is, in one line, and its own colours.
private struct AiService {
    let action: AiHomeAction
    let icon: String
    let title: String
    let sub: String
    let colors: [Color]
}

private let violet = [Color(hex: 0xA78BFA), Color(hex: 0x6D28D9)]
private let rose = [Color(hex: 0xFB7185), Color(hex: 0xBE123C)]
private let ocean = [Color(hex: 0x60A5FA), Color(hex: 0x1D4ED8)]
private let teal = [Color(hex: 0x2DD4BF), Color(hex: 0x0F766E)]
private let amber = [Color(hex: 0xFBBF24), Color(hex: 0xC2410C)]
private let indigo = [Color(hex: 0x22D3EE), Color(hex: 0x4338CA)]
private let emerald = [Color(hex: 0x34D399), Color(hex: 0x047857)]
private let sky = [Color(hex: 0x38BDF8), Color(hex: 0x0369A1)]
private let slate = [Color(hex: 0x94A3B8), Color(hex: 0x334155)]
private let navy = Color(hex: 0x001B53)

struct AiHomeScreen: View {
    @Environment(\.wa) private var wa
    let status: AiStatusDto?
    let loading: Bool
    let errorMessage: String?
    let conversations: [AiConversationDto]
    let onExit: () -> Void
    let onRetry: () -> Void
    let onAction: (AiHomeAction) -> Void
    let onOpenConversation: (Int) -> Void

    private var unavailable: Bool { status != nil && status?.available != true }
    private var brand: String { status?.brandName ?? "DORR AI" }

    private func act(_ a: AiHomeAction) {
        if !unavailable || [.subscription, .history, .settings].contains(a) { onAction(a) }
    }

    var body: some View {
        let c = wa.ai
        let create = [
            AiService(action: .image, icon: "photo.fill", title: L("ai_home_image"), sub: L("ai_home_image_sub"), colors: violet),
            AiService(action: .video, icon: "film.fill", title: L("ai_home_video"), sub: L("ai_home_video_sub"), colors: rose),
            AiService(action: .document, icon: "doc.text.fill", title: L("ai_home_document"), sub: L("ai_home_document_sub"), colors: ocean),
            AiService(action: .sites, icon: "globe.desk.fill", title: L("ai_sites_title"), sub: L("ai_home_sites_sub"), colors: teal),
            AiService(action: .world, icon: "globe.europe.africa.fill", title: L("ai_world_title"), sub: L("ai_home_world_sub"), colors: amber),
            AiService(action: .ar, icon: "arkit", title: L("ai_ar_title"), sub: L("ai_home_ar_sub"), colors: indigo),
        ]
        let work = [
            AiService(action: .projects, icon: "folder.fill", title: L("ai_tools_projects_title"), sub: L("ai_tools_projects_sub"), colors: ocean),
            AiService(action: .outputs, icon: "square.stack.fill", title: L("ai_tools_outputs_title"), sub: L("ai_tools_outputs_sub"), colors: violet),
            AiService(action: .search, icon: "magnifyingglass", title: L("ai_tools_search_title"), sub: L("ai_home_search_sub"), colors: sky),
            AiService(action: .continue, icon: "clock.arrow.circlepath", title: L("ai_tools_continue"), sub: L("ai_home_continue_sub"), colors: teal),
        ]
        let yours = [
            AiService(action: .memory, icon: "brain.head.profile", title: L("ai_tools_memory_title"), sub: L("ai_tools_memory_sub"), colors: violet),
            AiService(action: .language, icon: "character.bubble.fill", title: L("ai_language_title"), sub: L("ai_settings_language_sub"), colors: emerald),
            AiService(action: .subscription, icon: "crown.fill", title: L("ai_home_plans"), sub: L("ai_settings_subscription_sub"), colors: amber),
        ]
        VStack(spacing: 0) {
            // Top bar
            HStack(spacing: 6) {
                AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onExit)
                DorrLogo(width: 58, onDark: c.night)
                Text(verbatim: "AI").font(.dorr(11, .black)).foregroundStyle(.white).padding(.horizontal, 6).padding(.vertical, 1)
                    .background(c.red, in: RoundedRectangle(cornerRadius: 7, style: .continuous))
                Spacer()
                if !unavailable { AiRoundIcon(icon: "magnifyingglass", tint: c.mut) { act(.search) } }
                AiRoundIcon(icon: "gearshape.fill", tint: c.mut) { onAction(.settings) }
            }
            .padding(.horizontal, 8).padding(.vertical, 6)

            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    hero.aiAppear(0)
                    starters.aiAppear(1)
                    if loading && conversations.isEmpty && status == nil {
                        ProgressView().tint(c.red).frame(maxWidth: .infinity).padding(24)
                    }
                    if let errorMessage, conversations.isEmpty {
                        Button(action: onRetry) {
                            HStack {
                                Text(verbatim: errorMessage).font(.dorr(12.5)).foregroundStyle(Color(hex: 0xB91C1C)).frame(maxWidth: .infinity, alignment: .leading)
                                Text(L("ai_retry")).font(.dorr(12.5, .bold)).foregroundStyle(Color(hex: 0xB91C1C))
                            }
                            .padding(14)
                            .background(Color(hex: 0xFEE2E2), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                    if !conversations.isEmpty {
                        VStack(alignment: .leading, spacing: 10) {
                            sectionTitle(L("ai_home_recent"), action: L("ai_home_all_chats")) { onAction(.history) }
                            ScrollView(.horizontal, showsIndicators: false) {
                                HStack(spacing: 10) {
                                    ForEach(conversations.prefix(8)) { conv in recentCard(conv) }
                                }
                            }
                        }
                        .aiAppear(2)
                    }
                    VStack(alignment: .leading, spacing: 12) {
                        sectionTitle(L("ai_home_create"), sub: L("ai_home_create_sub"))
                        serviceGrid(create, enabled: !unavailable)
                    }
                    .aiAppear(3)
                    VStack(alignment: .leading, spacing: 12) {
                        sectionTitle(L("ai_home_work"), sub: L("ai_home_work_sub"))
                        serviceList(work, enabled: !unavailable)
                    }
                    .aiAppear(4)
                    VStack(alignment: .leading, spacing: 12) {
                        sectionTitle(L("ai_home_yours"), sub: L("ai_home_yours_sub"))
                        serviceList(yours, enabled: true)
                    }
                    .aiAppear(5)
                    Text(verbatim: L("ai_home_footer", brand)).font(.dorr(11)).foregroundStyle(c.mut).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).padding(.top, 4)
                }
                .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 32)
            }
        }
        .background(c.bg.ignoresSafeArea())
    }

    // MARK: Hero

    @MainActor private var hero: some View {
        let c = wa.ai
        let first = AuthSession.shared.user?.name?.trimmingCharacters(in: .whitespaces).split(separator: " ").first.map(String.init)
        return VStack(alignment: .leading, spacing: 0) {
            planChip
            Text(verbatim: first.map { L("ai_home_hello_name", $0) } ?? L("ai_home_hello")).font(.dorr(14, .medium)).foregroundStyle(.white.opacity(0.85)).padding(.top, 14)
            Text(L("ai_home_headline")).font(.dorr(23, .heavy)).foregroundStyle(.white).lineSpacing(4)
            if unavailable {
                Text(verbatim: L("ai_unavailable", brand)).font(.dorr(13)).foregroundStyle(.white).padding(12)
                    .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .padding(.top, 16)
            } else {
                // The ask bar: a tap starts a new chat; the mic is a live voice call.
                HStack(spacing: 10) {
                    Button { onAction(.newChat) } label: {
                        HStack(spacing: 10) {
                            Image(systemName: "sparkles").font(.system(size: 17)).foregroundStyle(c.red)
                            Text(verbatim: L("ai_home_ask", brand)).font(.dorr(14)).foregroundStyle(Color(hex: 0x6B7280)).lineLimit(1)
                            Spacer(minLength: 0)
                        }
                        .padding(14)
                        .background(Color.white, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .buttonStyle(AiPressStyle())
                    Button { onAction(.voice) } label: {
                        Image(systemName: "mic.fill").font(.system(size: 20)).foregroundStyle(.white)
                            .frame(width: 50, height: 50)
                            .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(Color.white.opacity(0.35), lineWidth: 1))
                    }
                    .buttonStyle(AiPressStyle())
                    .accessibilityLabel(L("ai_voice_mode"))
                }
                .padding(.top, 16)
                HStack(spacing: 6) {
                    Image(systemName: "waveform").font(.system(size: 12))
                    Text(L("ai_home_voice_hint")).font(.dorr(11.5))
                }
                .foregroundStyle(.white.opacity(0.75))
                .padding(.top, 10)
            }
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background {
            ZStack {
                LinearGradient(colors: [navy, Color(hex: 0x0B2E7A), c.red], startPoint: .topLeading, endPoint: .bottomTrailing)
                // Soft light in the corner, and the logo as a faint watermark.
                GeometryReader { g in
                    Circle().fill(Color.white.opacity(0.07)).frame(width: g.size.width * 0.9).position(x: g.size.width * 0.95, y: -g.size.height * 0.05)
                    Circle().fill(Color.white.opacity(0.05)).frame(width: g.size.width * 0.56).position(x: g.size.width * 0.05, y: g.size.height * 1.05)
                    DorrLogo(width: 150, onDark: true).opacity(0.07).position(x: g.size.width - 85, y: g.size.height - 95)
                }
                .environment(\.layoutDirection, .leftToRight)
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
        .shadow(color: c.red.opacity(c.night ? 0 : 0.35), radius: 14, y: 6)
    }

    /// Where the person stands with DORR AI — read-only; a tap opens the plans.
    @ViewBuilder private var planChip: some View {
        if let plan = status?.plan {
            let c = wa.ai
            let allowed = plan.allowed == true
            let (icon, text): (String, String) = {
                if !allowed && plan.reason == "blocked" { return ("lock.fill", L("ai_home_plan_blocked")) }
                if !allowed { return ("lock.fill", L("ai_home_plan_subscribe")) }
                if plan.isTrial == true { return ("sparkles", L("ai_home_plan_trial")) }
                return ("crown.fill", plan.planName ?? L("ai_home_plans"))
            }()
            Button { onAction(.subscription) } label: {
                HStack(spacing: 5) {
                    Image(systemName: icon).font(.system(size: 11))
                    Text(verbatim: text).font(.dorr(11.5, .bold))
                }
                .foregroundStyle(allowed ? .white : c.red)
                .padding(.horizontal, 10).padding(.vertical, 5)
                .background(allowed ? Color.white.opacity(0.16) : .white, in: Capsule())
            }
            .buttonStyle(.plain)
        }
    }

    // MARK: Starters

    /// One tap: a new chat that already says what to do (the server recognises each one).
    private var starters: some View {
        let c = wa.ai
        let list: [(AiHomeAction, String, String, [Color])] = [
            (.image, "photo.fill", L("ai_home_start_image"), violet),
            (.video, "film.fill", L("ai_home_start_video"), rose),
            (.document, "doc.text.fill", L("ai_home_start_document"), ocean),
            (.analyze, "doc.text.magnifyingglass", L("ai_home_start_analyze"), sky),
            (.translate, "translate", L("ai_home_start_translate"), emerald),
            (.code, "chevron.left.forwardslash.chevron.right", L("ai_home_start_code"), slate),
        ]
        return ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(list, id: \.0) { action, icon, label, colors in
                    Button { if !unavailable { onAction(action) } } label: {
                        HStack(spacing: 8) {
                            Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(.white)
                                .frame(width: 26, height: 26).background(LinearGradient(colors: colors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                            Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(c.ink)
                        }
                        .padding(.leading, 6).padding(.trailing, 14).padding(.vertical, 6)
                        .background(c.surface, in: Capsule())
                        .overlay(Capsule().stroke(c.line, lineWidth: 1))
                        .opacity(unavailable ? 0.45 : 1)
                    }
                    .buttonStyle(AiPressStyle())
                }
            }
        }
    }

    // MARK: Sections

    private func sectionTitle(_ title: String, sub: String? = nil, action: String? = nil, onAction: (() -> Void)? = nil) -> some View {
        let c = wa.ai
        return HStack(alignment: .bottom) {
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: title).font(.dorr(17, .heavy)).foregroundStyle(c.ink)
                if let sub { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(c.mut) }
            }
            Spacer()
            if let action, let onAction {
                Button(action: onAction) { Text(verbatim: action).font(.dorr(12.5, .bold)).foregroundStyle(c.red).padding(4) }.buttonStyle(.plain)
            }
        }
    }

    private func recentCard(_ conv: AiConversationDto) -> some View {
        let c = wa.ai
        return Button { onOpenConversation(conv.id) } label: {
            VStack(alignment: .leading, spacing: 0) {
                Image(systemName: "sparkles").font(.system(size: 14)).foregroundStyle(c.red)
                    .frame(width: 30, height: 30).background(c.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                Text(verbatim: (conv.title?.isEmpty == false ? conv.title! : L("ai_new_chat"))).font(.dorr(13.5, .bold)).foregroundStyle(c.ink)
                    .lineLimit(2).multilineTextAlignment(.leading).padding(.top, 10)
                Spacer(minLength: 0)
                Text(verbatim: aiTimeAgo(conv.updatedAt ?? conv.createdAt)).font(.dorr(11)).foregroundStyle(c.mut).padding(.top, 6)
            }
            .padding(14)
            .frame(width: 200, alignment: .leading)
            .frame(minHeight: 112)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(c.line, lineWidth: 1))
        }
        .buttonStyle(AiPressStyle())
    }

    /// The studio: two cards a row, each with its own colour.
    private func serviceGrid(_ services: [AiService], enabled: Bool) -> some View {
        let c = wa.ai
        return LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 10) {
            ForEach(services, id: \.action) { s in
                Button { if enabled { act(s.action) } } label: {
                    VStack(alignment: .leading, spacing: 0) {
                        Image(systemName: s.icon).font(.system(size: 20)).foregroundStyle(.white)
                            .frame(width: 44, height: 44)
                            .background(LinearGradient(colors: s.colors, startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .shadow(color: s.colors.last!.opacity(0.4), radius: 6, y: 3)
                        Text(verbatim: s.title).font(.dorr(14, .heavy)).foregroundStyle(c.ink).lineLimit(1).padding(.top, 12)
                        Text(verbatim: s.sub).font(.dorr(11.5)).foregroundStyle(c.mut).lineLimit(2).multilineTextAlignment(.leading).padding(.top, 3)
                        Spacer(minLength: 0)
                    }
                    .padding(14)
                    .frame(maxWidth: .infinity, minHeight: 138, alignment: .topLeading)
                    .background(c.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(c.line, lineWidth: 1))
                    .opacity(enabled ? 1 : 0.45)
                }
                .buttonStyle(AiPressStyle())
            }
        }
    }

    /// Quieter rows in one card — the person's own space and settings.
    private func serviceList(_ services: [AiService], enabled: Bool) -> some View {
        let c = wa.ai
        return VStack(spacing: 0) {
            ForEach(Array(services.enumerated()), id: \.offset) { i, s in
                Button { if enabled { act(s.action) } } label: {
                    HStack(spacing: 12) {
                        Image(systemName: s.icon).font(.system(size: 17)).foregroundStyle(.white)
                            .frame(width: 38, height: 38)
                            .background(LinearGradient(colors: s.colors, startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: s.title).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                            Text(verbatim: s.sub).font(.dorr(11.5)).foregroundStyle(c.mut).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        Image(systemName: "chevron.right").font(.system(size: 13, weight: .semibold)).foregroundStyle(c.mut).flipsForRightToLeftLayoutDirection(true)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 12)
                    .contentShape(Rectangle())
                    .opacity(enabled ? 1 : 0.45)
                }
                .buttonStyle(.plain)
                if i < services.count - 1 { Rectangle().fill(c.line).frame(height: 1).padding(.leading, 64).padding(.trailing, 14) }
            }
        }
        .background(c.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(c.line, lineWidth: 1))
    }
}
