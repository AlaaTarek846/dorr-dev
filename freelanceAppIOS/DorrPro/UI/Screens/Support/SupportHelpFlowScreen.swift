import SwiftUI

// freelanceApp `ui/screens/SupportHelpFlowScreen.kt` — the guided help (quick chat) that comes before a ticket.

/// One line of the guided conversation: what the assistant says, or what the customer picked.
private enum HelpLine: Identifiable {
    case bot(String, String)
    case me(String, String)

    var id: String {
        switch self {
        case .bot(let key, _), .me(let key, _): return key
        }
    }
}

/// The guided help that comes before a ticket: the assistant greets, shows the topics as a list, and each pick is
/// answered and either opens the topic's own sub-list or ends the path. At the end the customer has to choose one
/// of two buttons — "that solved it" (done, no ticket) or "I need an agent" (on to the ticket form, with the topic
/// already as its title). With no menu configured (or no connection) it goes straight to the ticket form.
@MainActor
struct SupportHelpFlowScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onSolved: () -> Void
    let onNeedAgent: (String?) -> Void
    /// The topics picked before (ids from the main menu down), so coming back from the ticket form lands where it left.
    var initialPath: [Int] = []
    var onPathChange: ([Int]) -> Void = { _ in }

    @State private var loading = true
    @State private var greeting = ""
    @State private var root: [SupportHelpNodeDto] = []
    /// The topics picked so far, from the main menu down.
    @State private var steps: [SupportHelpNodeDto] = []

    var body: some View {
        let current = steps.last
        let options = current.map { $0.children ?? [] } ?? root
        let finished = current != nil && (current?.children ?? []).isEmpty
        var lines: [HelpLine] = []
        if !greeting.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { lines.append(.bot("greeting", greeting)) }
        for step in steps {
            lines.append(.me("me-\(step.id ?? 0)", step.title ?? ""))
            if let answer = step.answer, !answer.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { lines.append(.bot("bot-\(step.id ?? 0)", answer)) }
        }
        return ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                // Back steps up the menu one topic at a time (Android's back button); at the top it leaves the flow.
                SubHeader(title: L("support_help_title"), onBack: { if steps.isEmpty { onBack() } else { steps.removeLast() } })
                if loading {
                    ScrollView { SkeletonList().padding(.horizontal, 14).padding(.vertical, 8) }
                        .scrollDisabled(true)
                        .frame(maxHeight: .infinity)
                } else {
                    ScrollViewReader { proxy in
                        ScrollView {
                            VStack(spacing: 8) {
                                ForEach(lines) { line in
                                    switch line {
                                    case .bot(_, let text): HelpBubble(text: text, mine: false)
                                    case .me(_, let text): HelpBubble(text: text, mine: true)
                                    }
                                }
                                Group {
                                    if finished {
                                        FinalChoice(onSolved: { report(current, true); onSolved() },
                                                    onNeedAgent: { report(current, false); onNeedAgent(current?.title) })
                                    } else {
                                        OptionsCard(options: options) { steps.append($0) }
                                    }
                                }
                                .id("tail")
                                Spacer().frame(height: 12)
                            }
                            .padding(.horizontal, 14)
                            .padding(.vertical, 8)
                        }
                        // The newest line (and the options under it) stay in view.
                        .onChange(of: steps.count) { _ in
                            withAnimation(.easeOut(duration: 0.3)) { proxy.scrollTo("tail", anchor: .bottom) }
                        }
                        .onAppear { proxy.scrollTo("tail", anchor: .bottom) }
                    }
                }
            }
        }
        // The quick chat gets the whole screen: the main bottom bar steps aside while it is open (like a support chat).
        .onAppear { ShellState.shared.immersive = true }
        .onDisappear { ShellState.shared.immersive = false }
        .task { await load() }
        .onChange(of: steps.map { $0.id ?? 0 }) { ids in if !loading { onPathChange(ids) } }
    }

    private func load() async {
        guard let data = try? await SupportClient.help().data, let items = data.items, !items.isEmpty else {
            onNeedAgent(nil)
            return
        }
        greeting = data.greeting ?? ""
        root = items
        // Back to where the customer was: walk the saved path down the tree.
        var level = items
        var restored: [SupportHelpNodeDto] = []
        for id in initialPath {
            guard let node = level.first(where: { $0.id == id }) else { break }
            restored.append(node)
            level = node.children ?? []
        }
        steps = restored
        loading = false
        onPathChange(restored.map { $0.id ?? 0 })
    }

    /// What the customer pressed is counted for the team (best effort: it never holds the flow up).
    private func report(_ topic: SupportHelpNodeDto?, _ solved: Bool) {
        guard let id = topic?.id else { return }
        Task.detached { _ = try? await SupportClient.helpFeedback(id, SupportAutoReplyFeedbackRequest(solved: solved)) }
    }
}

/// Text lines up by its own language: Arabic sits on the right edge, English on the left, whatever the screen's direction.
private func contentAlignment(_ text: String) -> TextAlignment {
    let rtl = text.unicodeScalars.first { CharacterSet.letters.contains($0) }.map { (0x0590...0x08FF).contains($0.value) } ?? false
    return rtl ? .trailing : .leading
}

private struct HelpBubble: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let text: String
    let mine: Bool
    @State private var shown = false

    var body: some View {
        let tailOnRight = mine == (direction == .leftToRight)
        let shape = SupportBubbleCorners(topLeft: 20, topRight: 20, bottomRight: tailOnRight ? 5 : 20, bottomLeft: tailOnRight ? 20 : 5)
        HStack {
            if mine { Spacer(minLength: 0) }
            Text(verbatim: text).font(.dorr(14.5)).lineSpacing(4).foregroundStyle(mine ? .white : wa.settingsInk)
                .multilineTextAlignment(contentAlignment(text))
                .padding(.horizontal, 14)
                .padding(.vertical, 10)
                .background(mine ? wa.red : (wa.night ? wa.darkCard : wa.settingsCard), in: shape)
                .overlay(shape.stroke(mine ? .clear : wa.settingsMut.opacity(0.18), lineWidth: 1))
                .frame(maxWidth: 300, alignment: mine ? .trailing : .leading)
            if !mine { Spacer(minLength: 0) }
        }
        .scaleEffect(shown ? 1 : 0.94)
        .offset(y: shown ? 0 : 8)
        .opacity(shown ? 1 : 0)
        .onAppear { withAnimation(.easeOut(duration: 0.22)) { shown = true } }
    }
}

/// The topics as one rounded card of rows with a chevron, like the help menus of the delivery apps.
private struct OptionsCard: View {
    @Environment(\.wa) private var wa
    let options: [SupportHelpNodeDto]
    let onPick: (SupportHelpNodeDto) -> Void

    var body: some View {
        VStack(spacing: 0) {
            ForEach(Array(options.enumerated()), id: \.offset) { index, option in
                Button { onPick(option) } label: {
                    HStack {
                        Text(verbatim: option.title ?? "").font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                            .multilineTextAlignment(contentAlignment(option.title ?? ""))
                        Spacer(minLength: 8)
                        Image(systemName: "chevron.forward").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.settingsInk)
                    }
                    .padding(.horizontal, 18)
                    .padding(.vertical, 16)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if index != options.count - 1 {
                    wa.settingsMut.opacity(0.15).frame(height: 1)
                }
            }
        }
        .background(wa.night ? wa.darkCard : wa.settingsCard, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.settingsMut.opacity(0.18), lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

/// The end of the path: there is no way on but one of these two.
private struct FinalChoice: View {
    @Environment(\.wa) private var wa
    let onSolved: () -> Void
    let onNeedAgent: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(L("support_help_final_question")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.horizontal, 4)
            Button(action: onSolved) {
                Text(L("support_help_solved")).font(.dorr(15, .heavy)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 15)
                    .background(wa.red, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(.plain)
            Button(action: onNeedAgent) {
                HStack(spacing: 8) {
                    Image(systemName: "headphones").font(.system(size: 17, weight: .semibold))
                    Text(L("support_help_need_agent")).font(.dorr(15, .heavy))
                }
                .foregroundStyle(wa.red)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 15)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(.plain)
        }
    }
}
