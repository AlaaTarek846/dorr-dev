import SwiftUI

// androidApp `chat/ChatAiToolsUi.kt`, `ChatAskUi.kt`, ChatAiUi's summary sheet + pill and ChatAiMoreUi's capsule — DORR
// AI tools in the chat (spec 31, 36–42, 46, 48, 49, 125–126) and my tasks (38). One tap each; the sheet says what goes
// to DORR AI, and nothing is sent, saved or scheduled until I confirm. Answers that touch religion, law, medicine,
// structural design or production code carry the approved alert card (spec 350–362) — the same card everywhere.

// MARK: - shared pieces

/// The alert card inside a reply (spec 350–362): the approved texts exactly as the server sent them.
struct AiSafetyCard: View {
    @ObservedObject private var ch = Ch.shared
    let safety: SafetyDto?

    var body: some View {
        if let s = safety, s.notice != nil || s.disclaimer != nil {
            let icon: String = {
                switch s.domain {
                case "medicine": return "cross.case.fill"
                case "law": return "hammer.fill"
                case "religion": return "book.fill"
                case "engineering": return "wrench.and.screwdriver.fill"
                default: return "chevron.left.forwardslash.chevron.right"
                }
            }()
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: icon).font(.system(size: 17)).foregroundStyle(Color(hex: 0xB45309))
                VStack(alignment: .leading, spacing: 4) {
                    if let n = s.notice { Text(verbatim: n).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink) }
                    if let d = s.disclaimer { Text(verbatim: d).font(.dorr(11.5)).foregroundStyle(ch.mut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .background(Color(hex: 0xF59E0B).opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color(hex: 0xF59E0B).opacity(0.45), lineWidth: 1))
        }
    }
}

/// The quality gate's note under an answer it couldn't fully check (spec 362) — the server's words.
struct VerificationNote: View {
    @ObservedObject private var ch = Ch.shared
    let verification: AiVerificationDto?

    var body: some View {
        if let notice = verification?.notice {
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: "checkmark.rectangle.stack").font(.system(size: 15)).foregroundStyle(ch.mut)
                Text(verbatim: notice).font(.dorr(12.5)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
    }
}

/// Three soft lines that breathe while the AI works.
struct AiShimmer: View {
    let ink: Color
    @State private var bright = false

    var body: some View {
        VStack(alignment: .leading, spacing: 5) {
            ForEach([1.0, 0.85, 0.55], id: \.self) { w in
                GeometryReader { g in Capsule().fill(ink.opacity((bright ? 0.6 : 0.25) * 0.35)).frame(width: g.size.width * w) }.frame(height: 9)
            }
        }
        .padding(.top, 6)
        .onAppear { withAnimation(.easeInOut(duration: 0.7).repeatForever(autoreverses: true)) { bright = true } }
    }
}

/// Sparkle badge, title and a line under it.
struct AiHeader: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    let sub: String?

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "sparkles").font(.system(size: 18)).foregroundStyle(.white)
                .frame(width: 38, height: 38).background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                if let sub { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(ch.mut) }
            }
            Spacer(minLength: 0)
        }
    }
}

private struct AiPrivacy: View {
    @ObservedObject private var ch = Ch.shared
    var body: some View { Text(L("ch_ai_privacy_note")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 10) }
}

private struct AiError: View {
    @ObservedObject private var ch = Ch.shared
    let text: String
    var body: some View { Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(ch.danger).padding(.vertical, 8) }
}

/// A message an answer rests on; a tap jumps to it.
private struct RefRow: View {
    @ObservedObject private var ch = Ch.shared
    let ref: AskReferenceDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(alignment: .top, spacing: 6) {
                Image(systemName: "quote.opening").font(.system(size: 14)).foregroundStyle(ch.red)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: (ref.sender ?? "") + "  ·  " + listTime(ref.createdAt)).font(.dorr(11.5, .bold)).foregroundStyle(ch.red)
                    Text(verbatim: ref.excerpt ?? "").font(.dorr(13)).foregroundStyle(ch.ink).multilineTextAlignment(.leading)
                }
                Spacer(minLength: 0)
            }
            .padding(10)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .padding(.top, 6)
    }
}

private struct AiToolChip: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 13)).foregroundStyle(ch.red)
                Text(verbatim: label).font(.dorr(12.5, .semibold)).foregroundStyle(ch.ink)
            }
            .padding(.horizontal, 12).padding(.vertical, 7)
            .background(ch.surface, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// A sheet's scrolling body, capped so the keyboard and the screen both fit.
private struct AiSheet<Content: View>: View {
    var maxHeight: CGFloat = 620
    @ViewBuilder let content: () -> Content

    var body: some View {
        ChSheetCard {
            ScrollView { VStack(alignment: .leading, spacing: 0, content: content) }.frame(maxHeight: maxHeight)
        }
    }
}

// MARK: - summary

/// «✨ Summarize what's new» above the messages when I come back to unread ones.
struct SummarizePill: View {
    @ObservedObject private var ch = Ch.shared
    let visible: Bool
    let action: () -> Void

    var body: some View {
        if visible {
            Button(action: action) {
                HStack(spacing: 6) {
                    Image(systemName: "sparkles").font(.system(size: 14))
                    Text(L("ch_ai_summarize_new")).font(.dorr(13, .bold))
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 16).padding(.vertical, 8)
                .background(ch.headerBrush, in: Capsule())
                .shadow(color: .black.opacity(0.2), radius: 6, y: 2)
            }
            .buttonStyle(.plain)
            .frame(maxWidth: .infinity)
            .padding(.top, 8)
            .transition(.move(edge: .top).combined(with: .opacity))
        }
    }
}

/// The AI's summary of this chat: what's new since I last read it, or the latest messages.
struct AiSummarySheet: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    @State var unreadOnly: Bool
    @State private var text: String?
    @State private var error: String?
    @State private var count = 0

    var body: some View {
        ChSheetCard {
            HStack(spacing: 10) {
                Image(systemName: "sparkles").font(.system(size: 18)).foregroundStyle(.white).frame(width: 38, height: 38).background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 12))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_ai_summary")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    if text != nil { Text(L("ch_ai_summary_from", count)).font(.dorr(12)).foregroundStyle(ch.mut) }
                }
            }
            HStack(spacing: 8) {
                scope(L("ch_ai_summary_unread"), unreadOnly) { unreadOnly = true }
                scope(L("ch_ai_summary_latest"), !unreadOnly) { unreadOnly = false }
            }
            .padding(.top, 12)
            ScrollView {
                Group {
                    if let error { Text(verbatim: error).font(.dorr(13.5)).foregroundStyle(ch.danger) } else if let text {
                        Text(verbatim: text).font(.dorr(14.5)).foregroundStyle(ch.ink).lineSpacing(4)
                    } else { AiShimmer(ink: ch.ink) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(minHeight: 90, maxHeight: 380)
            .padding(14)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.top, 14)
            AiPrivacy()
        }
        .task(id: unreadOnly) {
            text = nil
            error = nil
            do {
                let r = try await ChatApi.summarize(conversationId, unreadOnly: unreadOnly).data
                text = r?.text ?? ""
                count = r?.messages ?? 0
            } catch {
                self.error = error.apiFailure.message ?? L("ch_error_network")
            }
        }
    }

    private func scope(_ label: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : ch.ink).frame(maxWidth: .infinity).padding(.vertical, 9)
                .background(on ? ch.red : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - ask · commitments (125–126)

/// «Ask about this chat» — about the messages I picked or the latest ones; the answer shows what it rests on.
struct AiAskSheet: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let messageIds: [String]?
    let onDismiss: () -> Void
    let onJump: (String) -> Void
    @State private var question = ""
    @State private var busy = false
    @State private var answer: AskAnswerDto?
    @State private var error: String?

    var body: some View {
        ChSheetCard {
            AiHeader(title: L("ch_ai_ask"), sub: (messageIds ?? []).isEmpty ? L("ch_ai_ask_scope_latest") : L("ch_ai_ask_scope_picked", messageIds?.count ?? 0))
            HStack(spacing: 8) {
                ChField(placeholder: L("ch_ai_ask_hint"), text: $question, lines: 1...3, limit: 500)
                ChPrimaryButton(text: L("ch_ai_ask_go"), enabled: !question.trimmingCharacters(in: .whitespaces).isEmpty && !busy, full: false, action: ask)
            }
            .padding(.top, 12)
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if let error {
                        Text(verbatim: error).font(.dorr(13.5)).foregroundStyle(ch.danger)
                    } else if busy {
                        AiShimmer(ink: ch.ink)
                    } else if let a = answer {
                        Text(verbatim: a.answer).font(.dorr(15)).foregroundStyle(ch.ink).lineSpacing(4)
                        AiSafetyCard(safety: a.safety).padding(.top, 10)
                        VerificationNote(verification: a.verification).padding(.top, 8)
                        if !(a.references ?? []).isEmpty {
                            Text(L("ch_ai_ask_sources")).font(.dorr(12, .bold)).foregroundStyle(ch.mut).padding(.top, 10)
                            ForEach(a.references ?? []) { ref in RefRow(ref: ref) { onDismiss(); onJump(ref.id) } }
                        }
                    } else {
                        Text(L("ch_ai_ask_empty")).font(.dorr(13)).foregroundStyle(ch.soft)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(minHeight: 70, maxHeight: 420)
            .padding(14)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.top, 12)
            AiPrivacy()
        }
    }

    private func ask() {
        busy = true
        error = nil
        Task {
            do { answer = try await AiToolsApi.ask(conversationId, question: question.trimmingCharacters(in: .whitespacesAndNewlines), messages: messageIds).data } catch {
                self.error = error.apiFailure.message ?? L("ch_error_network")
            }
            busy = false
        }
    }
}

/// Tomorrow at 09:00, as an ISO time with my offset.
private func tomorrowMorning() -> String {
    let cal = Calendar.current
    let d = cal.date(bySettingHour: 9, minute: 0, second: 0, of: Date().addingTimeInterval(86400)) ?? Date()
    return isoWithOffset(d)
}

/// Promises and tasks found in the chat — suggestions only; «Remind me» sets a reminder on their message.
struct AiCommitmentsSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let onDismiss: () -> Void
    let onJump: (String) -> Void
    @State private var items: [CommitmentDto]?
    @State private var error: String?
    @State private var done: Set<Int> = []

    var body: some View {
        ChSheetCard {
            AiHeader(title: L("ch_ai_commitments"), sub: L("ch_ai_commitments_sub")).padding(.bottom, 12)
            ScrollView {
                VStack(spacing: 8) {
                    if let error {
                        AiError(text: error)
                    } else if let list = items {
                        if list.isEmpty { Text(L("ch_ai_commitments_none")).font(.dorr(14)).foregroundStyle(ch.mut).padding(.vertical, 18) }
                        ForEach(Array(list.enumerated()), id: \.offset) { i, c in card(i, c) }
                    } else {
                        AiShimmer(ink: ch.ink).padding(14).background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                    }
                }
            }
            .frame(maxHeight: 460)
            AiPrivacy()
        }
        .task {
            do { items = try await AiToolsApi.commitments(conversationId).data?.commitments ?? [] } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }

    private func card(_ i: Int, _ c: CommitmentDto) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: "checkmark.circle").font(.system(size: 18)).foregroundStyle(c.isMine == true ? ch.red : ch.mut)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: c.text).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink)
                    Text(verbatim: [c.isMine == true ? L("ch_ai_commitment_me") : c.owner, c.dueAt.map { listTime($0) + " " + clockTime($0) }].compactMap { $0 }.joined(separator: "  ·  "))
                        .font(.dorr(12)).foregroundStyle(ch.mut)
                }
                Spacer(minLength: 0)
            }
            if let id = c.messageId {
                HStack(spacing: 8) {
                    Button { onDismiss(); onJump(id) } label: {
                        Text(L("ch_ai_commitment_show")).font(.dorr(12.5, .bold)).foregroundStyle(ch.red).padding(.horizontal, 12).padding(.vertical, 7)
                            .background(ch.surface, in: RoundedRectangle(cornerRadius: 12))
                    }
                    .buttonStyle(.plain)
                    let isDone = done.contains(i)
                    Button {
                        // Confirmed by me: a reminder on that message, at its time or tomorrow 09:00.
                        Task {
                            do {
                                _ = try await ChatApi.setReminder(id, remindAt: c.dueAt ?? tomorrowMorning(), note: String(c.text.prefix(200)))
                                done.insert(i)
                                host.showToast(L("ch_ai_commitment_reminded"))
                            } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
                        }
                    } label: {
                        HStack(spacing: 5) {
                            Image(systemName: isDone ? "checkmark.circle.fill" : "alarm.fill").font(.system(size: 13))
                            Text(L(isDone ? "ch_ai_commitment_done" : "ch_ai_commitment_remind")).font(.dorr(12.5, .bold))
                        }
                        .foregroundStyle(.white).padding(.horizontal, 12).padding(.vertical, 7)
                        .background(isDone ? Color(hex: 0x16A34A) : ch.red, in: RoundedRectangle(cornerRadius: 12))
                    }
                    .buttonStyle(.plain)
                    .disabled(isDone)
                }
            }
        }
        .padding(12)
        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

// MARK: - 31 the assistant

private struct Turn: Identifiable {
    let id = UUID()
    let question: String
    var answer: AssistantAnswerDto?
    var error: String?
}

/// DORR AI inside the chat — only I see it. A general question sends nothing of the chat; with picked messages only
/// those go. A little conversation, kept on the phone.
struct AiAssistantSheet: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let messageIds: [String]?
    let onDismiss: () -> Void
    let onJump: (String) -> Void
    let onUse: (String) -> Void
    @State private var turns: [Turn] = []
    @State private var question = ""
    @State private var busy = false

    var body: some View {
        ChSheetCard {
            AiHeader(title: L("ct_assistant"), sub: (messageIds ?? []).isEmpty ? L("ct_assistant_sub") : L("ch_ai_ask_scope_picked", messageIds?.count ?? 0))
            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 12) {
                        if turns.isEmpty { Text(L("ct_assistant_empty")).font(.dorr(13)).foregroundStyle(ch.soft).padding(.vertical, 18) }
                        ForEach(turns) { t in turn(t).id(t.id) }
                    }
                }
                .frame(minHeight: 120, maxHeight: 460)
                .onChange(of: turns.count) { _ in if let last = turns.last { withAnimation { proxy.scrollTo(last.id, anchor: .bottom) } } }
            }
            .padding(.top, 10)
            HStack(spacing: 8) {
                ChField(placeholder: L("ct_assistant_hint"), text: $question, lines: 1...4, limit: 1000)
                ChPrimaryButton(text: L("ch_ai_ask_go"), enabled: !question.trimmingCharacters(in: .whitespaces).isEmpty && !busy, full: false, action: ask)
            }
            AiPrivacy()
        }
    }

    private func turn(_ t: Turn) -> some View {
        VStack(alignment: .trailing, spacing: 6) {
            Text(verbatim: t.question).font(.dorr(14)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 9)
                .background(ch.red, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                if let e = t.error {
                    AiError(text: e)
                } else if let a = t.answer {
                    Text(verbatim: a.answer).font(.dorr(14.5)).foregroundStyle(ch.ink).lineSpacing(4)
                    AiSafetyCard(safety: a.safety).padding(.top, 10)
                    VerificationNote(verification: a.verification).padding(.top, 8)
                    ForEach(a.references ?? []) { ref in RefRow(ref: ref) { onDismiss(); onJump(ref.id) } }
                    HStack(spacing: 14) {
                        smallAction("doc.on.doc", L("ch_copy")) { UIPasteboard.general.string = a.answer }
                        smallAction("paperplane", L("ct_use_in_chat")) { onUse(a.answer); onDismiss() }
                    }
                    .padding(.top, 8)
                } else {
                    AiShimmer(ink: ch.ink)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
    }

    private func smallAction(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 13))
                Text(verbatim: label).font(.dorr(12.5, .bold))
            }
            .foregroundStyle(ch.red).padding(4)
        }
        .buttonStyle(.plain)
    }

    private func ask() {
        let q = question.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !q.isEmpty else { return }
        question = ""
        busy = true
        let history = turns.filter { $0.answer != nil }.suffix(5)
        turns.append(Turn(question: q))
        let index = turns.count - 1
        Task {
            var body: [String: JSONValue] = [
                "question": .string(q),
                "history": .array(history.map { .object(["q": .string($0.question), "a": .string($0.answer?.answer ?? "")]) }),
            ]
            if let ids = messageIds, !ids.isEmpty { body["messages"] = .array(ids.map { .string($0) }) }
            do { turns[index].answer = try await AiToolsApi.assistant(conversationId, body).data } catch {
                turns[index].error = error.apiFailure.message ?? L("ch_error_network")
            }
            busy = false
        }
    }
}

// MARK: - 36 proofread

/// «✓ Fix spelling» above the composer once I've written something: one tap sends only that text and puts the
/// corrected one in its place — with an undo for 6 seconds.
struct ProofreadBar: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ai = ChatAi.shared
    let text: String
    let enabled: Bool
    let onReplace: (String) -> Void
    @State private var busy = false
    @State private var undo: String?

    var body: some View {
        let visible = enabled && ai.proofread && (text.trimmingCharacters(in: .whitespaces).count >= 6 || undo != nil)
        HStack(spacing: 6) {
            if visible {
                if let before = undo {
                    AiToolChip(icon: "arrow.uturn.backward", label: L("ct_proof_undo")) { onReplace(before); undo = nil }
                } else {
                    AiToolChip(icon: "textformat.abc.dottedunderline", label: L(busy ? "ct_proof_busy" : "ct_proof"), action: fix)
                }
                Spacer()
            }
        }
        .padding(.bottom, visible ? 6 : 0)
        .animation(.easeOut(duration: 0.2), value: visible)
        .task(id: undo) {
            guard undo != nil else { return }
            try? await Task.sleep(nanoseconds: 6_000_000_000)
            if !Task.isCancelled { undo = nil }
        }
    }

    private func fix() {
        guard !busy else { return }
        busy = true
        let original = text
        Task {
            do {
                if let r = try await AiToolsApi.proofread(original).data, r.changed == true {
                    undo = original
                    onReplace(r.text)
                } else {
                    host.showToast(L("ct_proof_clean"))
                }
            } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
            }
            busy = false
        }
    }
}

// MARK: - 37 + 42 understand

private func intentLabel(_ intent: String?) -> String {
    let known = ["question", "request", "invitation", "appointment", "payment", "information", "complaint", "greeting"]
    return L("ct_intent_" + (known.contains(intent ?? "") ? intent! : "other"))
}

private func toneLabel(_ tone: String?) -> String {
    let known = ["positive", "negative", "urgent", "worried", "angry", "joking"]
    return L("ct_tone_" + (known.contains(tone ?? "") ? tone! : "neutral"))
}

private func toneColor(_ tone: String?) -> Color {
    switch tone {
    case "positive", "joking": return Color(hex: 0x059669)
    case "negative", "angry": return Color(hex: 0xDC2626)
    case "urgent", "worried": return Color(hex: 0xD97706)
    default: return Color(hex: 0x6B7280)
    }
}

private struct Tag: View {
    let text: String
    let color: Color
    var body: some View {
        Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(color).padding(.horizontal, 10).padding(.vertical, 4).background(color.opacity(0.12), in: Capsule())
    }
}

/// What the message wants, its tone, and what I could do — reply, remind, task, note.
struct AiUnderstandSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var ai = ChatAi.shared
    let message: MessageDto
    let onDismiss: () -> Void
    let onReply: (String) -> Void
    let onRemind: () -> Void
    let onTasks: () -> Void
    let onNote: () -> Void
    @State private var result: UnderstandDto?
    @State private var error: String?

    var body: some View {
        AiSheet {
            AiHeader(title: L("ct_understand"), sub: message.sender?.name).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let r = result {
                HStack(spacing: 6) {
                    Tag(text: intentLabel(r.intent), color: ch.red)
                    Tag(text: toneLabel(r.tone), color: toneColor(r.tone))
                }
                if let s = r.summary, !s.isEmpty { Text(verbatim: s).font(.dorr(15, .semibold)).foregroundStyle(ch.ink).padding(.top, 10) }
                if let n = r.toneNote { Text(verbatim: n).font(.dorr(13)).foregroundStyle(ch.mut).padding(.top, 4) }
                AiSafetyCard(safety: r.safety).padding(.top, 10)
                if !(r.replies ?? []).isEmpty {
                    Text(L("ct_suggested_replies")).font(.dorr(12, .bold)).foregroundStyle(ch.mut).padding(.top, 14).padding(.bottom, 4)
                    ForEach(r.replies ?? [], id: \.self) { reply in
                        Button { onReply(reply); onDismiss() } label: {
                            Text(verbatim: reply).font(.dorr(14)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading).padding(12)
                                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .padding(.top, 6)
                    }
                }
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        if (r.actions ?? []).contains("remind") || ["appointment", "request", "payment"].contains(r.intent ?? "") {
                            AiToolChip(icon: "alarm", label: L("ch_reminder")) { onDismiss(); onRemind() }
                        }
                        if ai.tasks { AiToolChip(icon: "checkmark.circle", label: L("ct_make_tasks")) { onDismiss(); onTasks() } }
                        if ai.notes { AiToolChip(icon: "doc.text", label: L("ct_make_note")) { onDismiss(); onNote() } }
                    }
                }
                .padding(.top, 14)
            } else {
                AiShimmer(ink: ch.ink).padding(24)
            }
            AiPrivacy()
        }
        .task(id: message.id) {
            do { result = try await AiToolsApi.understand(message.id).data } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }
}

// MARK: - 46 simplify

struct AiSimplifySheet: View {
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    @State private var result: SimplifyDto?
    @State private var error: String?

    var body: some View {
        AiSheet {
            AiHeader(title: L("ct_simplify"), sub: message.sender?.name).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let r = result {
                Text(verbatim: r.short ?? "").font(.dorr(15.5, .semibold)).foregroundStyle(ch.ink).lineSpacing(4)
                ForEach(r.points ?? [], id: \.self) { p in
                    HStack(alignment: .top, spacing: 8) {
                        Circle().fill(ch.red).frame(width: 6, height: 6).padding(.top, 8)
                        Text(verbatim: p).font(.dorr(14)).foregroundStyle(ch.ink)
                    }
                    .padding(.top, 8)
                }
            } else {
                AiShimmer(ink: ch.ink).padding(24)
            }
            AiPrivacy()
        }
        .task(id: message.id) {
            do { result = try await AiToolsApi.simplify(message.id).data } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }
}

// MARK: - 38 tasks from a message

/// Suggested tasks from a message — tick the ones to keep, then save to «My tasks».
struct AiTasksFromSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    let onDismiss: () -> Void
    @State private var items: [TaskSuggestionDto]?
    @State private var picked: Set<Int> = []
    @State private var error: String?

    var body: some View {
        AiSheet {
            AiHeader(title: L("ct_make_tasks"), sub: nil).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let list = items {
                if list.isEmpty { Text(L("ct_tasks_none")).font(.dorr(13.5)).foregroundStyle(ch.soft) }
                ForEach(Array(list.enumerated()), id: \.offset) { i, t in
                    let on = picked.contains(i)
                    Button { if on { picked.remove(i) } else { picked.insert(i) } } label: {
                        HStack(spacing: 10) {
                            Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(on ? ch.red : ch.soft)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(verbatim: t.text).font(.dorr(14.5)).foregroundStyle(ch.ink).multilineTextAlignment(.leading)
                                if let d = t.dueAt { Text(verbatim: listTime(d) + " " + clockTime(d)).font(.dorr(12, .bold)).foregroundStyle(ch.red) }
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(.vertical, 8).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                if !list.isEmpty {
                    ChPrimaryButton(text: L("ct_tasks_save", picked.count), icon: "checkmark.circle", enabled: !picked.isEmpty) {
                        Task {
                            do {
                                _ = try await AiToolsApi.createTasks(picked.sorted().map { list[$0] }, messageId: message.id)
                                host.showToast(L("ct_tasks_saved"))
                                onDismiss()
                            } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
                        }
                    }
                    .padding(.top, 12)
                }
            } else {
                AiShimmer(ink: ch.ink).padding(24)
            }
            AiPrivacy()
        }
        .task(id: message.id) {
            do {
                let list = try await AiToolsApi.tasksFrom(message.id).data?.tasks ?? []
                items = list
                picked = Set(list.indices)
            } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }
}

// MARK: - 40 dates in the chat

/// Dates mentioned in the chat — into my calendar, or «Remind me» on that message at that time.
struct AiDatesSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let onDismiss: () -> Void
    let onJump: (String) -> Void
    @State private var items: [DateFoundDto]?
    @State private var done: Set<Int> = []
    @State private var error: String?

    var body: some View {
        AiSheet {
            AiHeader(title: L("ct_dates"), sub: L("ct_dates_sub")).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let list = items {
                if list.isEmpty { Text(L("ct_dates_none")).font(.dorr(13.5)).foregroundStyle(ch.soft) }
                ForEach(Array(list.enumerated()), id: \.offset) { i, d in row(i, d) }
            } else {
                AiShimmer(ink: ch.ink).padding(24)
            }
            AiPrivacy()
        }
        .task {
            do { items = try await AiToolsApi.dates(conversationId).data?.dates ?? [] } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }

    private func row(_ i: Int, _ d: DateFoundDto) -> some View {
        let isDone = done.contains(i)
        return HStack(spacing: 10) {
            Image(systemName: "clock.fill").font(.system(size: 19)).foregroundStyle(ch.red)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: d.title).font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink)
                Text(verbatim: listTime(d.at) + (d.allDay == true ? "" : " " + clockTime(d.at))).font(.dorr(12.5)).foregroundStyle(ch.mut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .contentShape(Rectangle())
            .onTapGesture { if let m = d.messageId { onDismiss(); onJump(m) } }
            // Into my calendar (spec 201) — the same event twice stays one.
            Button {
                Task {
                    var body: [String: JSONValue] = ["title": .string(d.title), "timezone": .string(TimeZone.current.identifier)]
                    if d.allDay == true { body["all_day"] = .bool(true); body["date"] = .string(String(d.at.prefix(10))) } else { body["starts_at"] = .string(d.at) }
                    if let m = d.messageId { body["message_id"] = .string(m) }
                    do {
                        _ = try await CalendarApi.create(body)
                        host.showToast(L("cal_saved"))
                    } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
                }
            } label: {
                Image(systemName: "calendar.badge.plus").font(.system(size: 15)).foregroundStyle(ch.red).frame(width: 32, height: 32).background(ch.red.opacity(0.1), in: Circle())
            }
            .buttonStyle(.plain)
            if let m = d.messageId {
                Button {
                    Task {
                        do {
                            _ = try await ChatApi.setReminder(m, remindAt: d.at, note: String(d.title.prefix(200)))
                            done.insert(i)
                            host.showToast(L("ch_ai_commitment_reminded"))
                        } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
                    }
                } label: {
                    Text(L(isDone ? "ch_ai_commitment_done" : "ch_ai_commitment_remind")).font(.dorr(12.5, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 12).padding(.vertical, 7).background(isDone ? Color(hex: 0x10B981) : ch.red, in: Capsule())
                }
                .buttonStyle(.plain)
                .disabled(isDone)
            }
        }
        .padding(12)
        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .padding(.bottom, 8)
    }
}

// MARK: - 48 related files

struct AiRelatedFilesSheet: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let onDismiss: () -> Void
    let onJump: (String) -> Void
    @State private var items: [RelatedFileDto]?
    @State private var error: String?

    var body: some View {
        AiSheet {
            AiHeader(title: L("ct_files"), sub: L("ct_files_sub")).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let list = items {
                if list.isEmpty { Text(L("ct_files_none")).font(.dorr(13.5)).foregroundStyle(ch.soft) }
                ForEach(Array(list.enumerated()), id: \.offset) { _, f in
                    Button { onDismiss(); onJump(f.messageId) } label: {
                        HStack(spacing: 10) {
                            Image(systemName: "doc.text.fill").font(.system(size: 21)).foregroundStyle(ch.red)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(verbatim: f.name ?? f.type).font(.dorr(14, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
                                Text(verbatim: [f.why.flatMap { $0.isEmpty ? nil : $0 }, listTime(f.createdAt)].compactMap { $0 }.joined(separator: "  ·  "))
                                    .font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(2).multilineTextAlignment(.leading)
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(12)
                        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .padding(.bottom, 8)
                }
            } else {
                AiShimmer(ink: ch.ink).padding(24)
            }
            AiPrivacy()
        }
        .task {
            do { items = try await AiToolsApi.relatedFiles(conversationId).data?.files ?? [] } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }
}

// MARK: - 41 important · 49 today

/// Sparkle + count, and the AI privacy line next to it.
private struct AiPageIntro: View {
    @ObservedObject private var ch = Ch.shared
    let sub: String

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "sparkles").font(.system(size: 18)).foregroundStyle(.white).frame(width: 38, height: 38).background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 12))
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: sub).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink)
                Text(L("ch_ai_privacy_note")).font(.dorr(11.5)).foregroundStyle(ch.mut)
            }
            Spacer(minLength: 0)
        }
        .padding(.bottom, 12)
    }
}

private struct ImportantCard: View {
    @ObservedObject private var ch = Ch.shared
    let item: AiMessageRefDto
    let showChat: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(alignment: .top, spacing: 10) {
                Circle().fill(item.level == "high" ? ch.danger : Color(hex: 0xF59E0B)).frame(width: 9, height: 9).padding(.top, 5)
                VStack(alignment: .leading, spacing: 2) {
                    HStack {
                        Text(verbatim: [showChat ? item.chat : nil, item.sender].compactMap { $0 }.joined(separator: " · ")).font(.dorr(13.5, .bold)).foregroundStyle(ch.red).lineLimit(1)
                        Spacer()
                        if let at = item.createdAt { Text(verbatim: listTime(at)).font(.dorr(11)).foregroundStyle(ch.mut) }
                    }
                    Text(verbatim: item.excerpt ?? "").font(.dorr(14)).foregroundStyle(ch.ink).lineLimit(3).multilineTextAlignment(.leading).padding(.top, 2)
                    if let why = item.why, !why.isEmpty { Text(verbatim: why).font(.dorr(12)).foregroundStyle(ch.mut) }
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 11)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
        }
        .buttonStyle(.plain)
        .padding(.bottom, 8)
    }
}

/// Across my unread chats: what deserves attention, pages of 15.
struct ImportantPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var items: [AiMessageRefDto]?
    @State private var messages = 0
    @State private var chats = 0
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var error: String?

    var body: some View {
        ChSettingsPage(title: L("ct_important"), onBack: { host.pop() }) {
            if let error, (items ?? []).isEmpty {
                AiError(text: error).padding(20)
                Spacer()
            } else if let list = items {
                if list.isEmpty {
                    ChEmptyState(icon: "exclamationmark.circle.fill", title: L("ct_important"), text: L("ct_important_none"))
                } else {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            AiPageIntro(sub: L("ct_today_sub", messages, chats))
                            ForEach(Array(list.enumerated()), id: \.element.messageId) { i, item in
                                ImportantCard(item: item, showChat: true) { open(item) }
                                    .onAppear { if i >= list.count - 3 { Task { await load(reset: false) } } }
                            }
                            if loadingMore { ProgressView().tint(ch.red).padding(16) }
                        }
                        .padding(.horizontal, 14).padding(.bottom, 24)
                    }
                }
            } else {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            }
        }
        .task { await load(reset: true) }
    }

    private func open(_ item: AiMessageRefDto) {
        guard let c = item.conversationId else { return }
        host.focusRequest = (c, item.messageId)
        host.push(.conversation(c))
    }

    private func load(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        do {
            let chunk = try await AiToolsApi.important(conversationId: nil, page: next).data
            let fresh = chunk?.items ?? []
            if reset {
                items = fresh
                messages = chunk?.messages ?? 0
                chats = chunk?.chats ?? 0
            } else {
                let known = Set((items ?? []).map(\.messageId))
                items = (items ?? []) + fresh.filter { !known.contains($0.messageId) }
            }
            page = next
            hasMore = chunk?.hasMore == true
            error = nil
        } catch {
            let fail = error.apiFailure
            if reset && fail.httpStatus == 422 {
                items = []
                hasMore = false
            } else if reset {
                items = items ?? []
                self.error = fail.message ?? L("ch_error_network")
            }
        }
        if !reset { loadingMore = false }
    }
}

/// Today across my chats: a few lines, and the messages that matter.
struct TodayPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var result: TodayDto?
    @State private var error: String?

    var body: some View {
        ChSettingsPage(title: L("ct_today"), onBack: { host.pop() }) {
            if let error {
                AiError(text: error).padding(20)
                Spacer()
            } else if let r = result {
                if (r.summary ?? []).isEmpty && (r.highlights ?? []).isEmpty {
                    ChEmptyState(icon: "sparkles", title: L("ct_today"), text: L("ct_important_none"))
                } else {
                    ScrollView {
                        VStack(alignment: .leading, spacing: 0) {
                            AiPageIntro(sub: L("ct_today_sub", r.messages ?? 0, r.chats ?? 0))
                            let summary = r.summary ?? []
                            if !summary.isEmpty {
                                VStack(spacing: 0) {
                                    ForEach(Array(summary.enumerated()), id: \.offset) { i, p in
                                        HStack(alignment: .top, spacing: 8) {
                                            Circle().fill(ch.red).frame(width: 6, height: 6).padding(.top, 8)
                                            Text(verbatim: p).font(.dorr(14)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                                        }
                                        .padding(.horizontal, 12).padding(.vertical, 11)
                                        if i != summary.count - 1 { Rectangle().fill(ch.red.opacity(0.08)).frame(height: 1).padding(.leading, 14) }
                                    }
                                }
                                .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            }
                            if !(r.highlights ?? []).isEmpty {
                                HStack(spacing: 6) {
                                    Image(systemName: "eye.fill").font(.system(size: 14)).foregroundStyle(ch.red)
                                    Text(L("ct_today_highlights")).font(.dorr(13.5, .heavy)).foregroundStyle(ch.ink)
                                }
                                .padding(.top, 14).padding(.bottom, 8)
                                ForEach(Array((r.highlights ?? []).enumerated()), id: \.offset) { _, h in
                                    Button {
                                        if let c = h.conversationId { host.focusRequest = (c, h.messageId); host.push(.conversation(c)) }
                                    } label: {
                                        VStack(alignment: .leading, spacing: 2) {
                                            Text(verbatim: [h.chat, h.sender].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12, .bold)).foregroundStyle(ch.red)
                                            Text(verbatim: h.text.flatMap { $0.isEmpty ? nil : $0 } ?? h.excerpt ?? "").font(.dorr(14)).foregroundStyle(ch.ink).multilineTextAlignment(.leading)
                                        }
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                        .padding(.horizontal, 12).padding(.vertical, 11)
                                        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                                    }
                                    .buttonStyle(.plain)
                                    .padding(.bottom, 8)
                                }
                            }
                        }
                        .padding(.horizontal, 14).padding(.bottom, 24)
                    }
                }
            } else {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            }
        }
        .task {
            do { result = try await AiToolsApi.today().data ?? TodayDto() } catch {
                let fail = error.apiFailure
                if fail.httpStatus == 422 { result = TodayDto() } else { self.error = fail.message ?? L("ch_error_network") }
            }
        }
    }
}

/// What deserves attention in this chat (from the conversation's AI menu).
struct AiImportantSheet: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String?
    let onDismiss: () -> Void
    let onOpen: (String, String) -> Void
    @State private var result: ImportantDto?
    @State private var error: String?

    var body: some View {
        AiSheet {
            AiHeader(title: L("ct_important"), sub: L(conversationId == nil ? "ct_important_sub_all" : "ct_important_sub_chat")).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let r = result {
                if (r.items ?? []).isEmpty { Text(L("ct_important_none")).font(.dorr(13.5)).foregroundStyle(ch.soft) }
                ForEach(Array((r.items ?? []).enumerated()), id: \.offset) { _, item in
                    ImportantCard(item: item, showChat: conversationId == nil) {
                        if let c = item.conversationId ?? conversationId { onDismiss(); onOpen(c, item.messageId) }
                    }
                }
            } else {
                AiShimmer(ink: ch.ink).padding(24)
            }
            AiPrivacy()
        }
        .task {
            do { result = try await AiToolsApi.important(conversationId: conversationId).data } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }
}

// MARK: - 38 my tasks

/// My tasks: open ones (due first), add one by hand, tick off, and the done ones below. Pages of 15.
struct TasksPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var open: [TaskDto]?
    @State private var done: [TaskDto] = []
    @State private var openPage = 1
    @State private var donePage = 1
    @State private var openHasMore = false
    @State private var doneHasMore = false
    @State private var loadingMore = false
    @State private var adding = ""
    @State private var dueFor: TaskDto?
    @State private var pendingDelete: TaskDto?

    var body: some View {
        ChSettingsPage(title: L("ct_tasks"), onBack: { host.pop() }) {
            ScrollView {
                LazyVStack(spacing: 0) {
                    HStack(spacing: 8) {
                        ChField(placeholder: L("ct_tasks_add_hint"), text: $adding, icon: "plus", limit: 300)
                        Button(action: add) {
                            Text(L("ct_tasks_add")).font(.dorr(12.5, .heavy)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 9)
                                .background(LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .leading, endPoint: .trailing), in: RoundedRectangle(cornerRadius: 14))
                                .shadow(color: ch.red.opacity(0.45), radius: 8, y: 3)
                                .opacity(adding.trimmingCharacters(in: .whitespaces).isEmpty ? 0.5 : 1)
                        }
                        .buttonStyle(.plain)
                        .disabled(adding.trimmingCharacters(in: .whitespaces).isEmpty)
                    }
                    .padding(.top, 4).padding(.bottom, 14)
                    if let list = open {
                        if list.isEmpty && done.isEmpty {
                            Text(L("ct_tasks_empty")).font(.dorr(14)).foregroundStyle(ch.soft).frame(maxWidth: .infinity, alignment: .leading).padding(.vertical, 20)
                        }
                        ForEach(Array(list.enumerated()), id: \.element.id) { i, t in
                            TaskRow(t: t, onToggle: { update(t, ["done": .bool(true)]) }, onDue: { dueFor = t }, onOpen: {
                                if let c = t.conversationId {
                                    if let m = t.messageId { host.focusRequest = (c, m) }
                                    host.push(.conversation(c))
                                }
                            }, onDelete: { pendingDelete = t })
                            .onAppear { if i >= list.count - 3 { Task { await more() } } }
                        }
                        if !done.isEmpty {
                            HStack(spacing: 8) {
                                Circle().fill(Color(hex: 0x10B981)).frame(width: 8, height: 8)
                                Text(L("ct_tasks_done")).font(.dorr(13, .bold)).foregroundStyle(ch.mut)
                                Rectangle().fill(ch.line).frame(height: 1)
                            }
                            .padding(.top, 18).padding(.bottom, 10)
                            ForEach(Array(done.enumerated()), id: \.element.id) { i, t in
                                TaskRow(t: t, onToggle: { update(t, ["done": .bool(false)]) }, onDue: {}, onOpen: {}, onDelete: { pendingDelete = t })
                                    .onAppear { if i >= done.count - 3 { Task { await more() } } }
                            }
                        }
                    } else {
                        ProgressView().tint(ch.red).padding(30)
                    }
                    if loadingMore { ProgressView().tint(ch.red).padding(16) }
                }
                .padding(.horizontal, 14).padding(.bottom, 24)
            }
        }
        .task { await reload() }
        .dorrDialog(isPresented: Binding(get: { dueFor != nil }, set: { if !$0 { dueFor = nil } }), bottom: true) {
            if let t = dueFor {
                ScheduleSheet(onDismiss: { dueFor = nil }) { at in
                    update(t, ["due_at": .string(isoWithOffset(at))], ok: L("ct_tasks_due_set"))
                    dueFor = nil
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { pendingDelete != nil }, set: { if !$0 { pendingDelete = nil } })) {
            if let t = pendingDelete {
                ConfirmDialog(icon: "trash", title: L("ct_tasks_delete_title"), message: L("ct_tasks_delete_msg"), confirmLabel: L("ch_delete"), onConfirm: {
                    pendingDelete = nil
                    Task {
                        do {
                            _ = try await AiToolsApi.deleteTask(t.id)
                            host.showToast(L("ct_tasks_deleted"))
                            await reload()
                        } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
                    }
                }, onDismiss: { pendingDelete = nil })
            }
        }
    }

    private func loadOpen(reset: Bool) async {
        if !reset && (loadingMore || !openHasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : openPage + 1
        let envelope = try? await AiToolsApi.tasks(page: next, perPage: 15)
        let chunk = envelope?.data ?? []
        if reset { open = chunk } else {
            let known = Set((open ?? []).map(\.id))
            open = (open ?? []) + chunk.filter { !known.contains($0.id) }
        }
        openPage = next
        openHasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }

    private func loadDone(reset: Bool) async {
        if !reset && (loadingMore || !doneHasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : donePage + 1
        let envelope = try? await AiToolsApi.tasks(status: "done", page: next, perPage: 15)
        let chunk = envelope?.data ?? []
        if reset { done = chunk } else {
            let known = Set(done.map(\.id))
            done += chunk.filter { !known.contains($0.id) }
        }
        donePage = next
        doneHasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }

    private func reload() async {
        await loadOpen(reset: true)
        await loadDone(reset: true)
    }

    private func more() async { if openHasMore { await loadOpen(reset: false) } else { await loadDone(reset: false) } }

    private func update(_ t: TaskDto, _ body: [String: JSONValue], ok: String? = nil) {
        Task {
            do {
                _ = try await AiToolsApi.updateTask(t.id, body)
                if let ok { host.showToast(ok) }
            } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
            await reload()
        }
    }

    private func add() {
        let text = adding.trimmingCharacters(in: .whitespacesAndNewlines)
        adding = ""
        Task {
            do {
                _ = try await AiToolsApi.createTasks([TaskSuggestionDto(text: text)], messageId: nil)
                host.showToast(L("ct_tasks_saved"))
                await reload()
            } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
        }
    }
}

private struct TaskRow: View {
    @ObservedObject private var ch = Ch.shared
    let t: TaskDto
    let onToggle: () -> Void
    let onDue: () -> Void
    let onOpen: () -> Void
    let onDelete: () -> Void
    @State private var open = false

    var body: some View {
        let isDone = t.done == true
        VStack(spacing: 10) {
            HStack(spacing: 10) {
                Button(action: onToggle) {
                    Image(systemName: isDone ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(isDone ? Color(hex: 0x10B981) : ch.soft)
                }
                .buttonStyle(.plain)
                Text(verbatim: t.text).font(.dorr(14.5, .semibold)).foregroundStyle(isDone ? ch.soft : ch.ink).lineLimit(open ? 8 : 1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .contentShape(Rectangle())
                    .onTapGesture { if t.conversationId != nil { onOpen() } }
                iconButton(open ? "chevron.up" : "chevron.down") { withAnimation { open.toggle() } }
            }
            Rectangle().fill(ch.line).frame(height: 1)
            HStack(spacing: 6) {
                if let due = t.dueAt {
                    Image(systemName: "clock.fill").font(.system(size: 12)).foregroundStyle(ch.red)
                    Text(verbatim: listTime(due) + " " + clockTime(due)).font(.dorr(12, .bold)).foregroundStyle(ch.red).lineLimit(1)
                }
                Spacer()
                if !isDone { iconButton("alarm", onDue) }
                iconButton("trash", onDelete)
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .padding(.bottom, 8)
    }

    private func iconButton(_ icon: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(ch.mut).frame(width: 32, height: 32).background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 10))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - continue in another chat

/// A summary of this chat written by DORR AI from the latest messages, shown first and editable; sent only to the
/// chats I pick.
struct ChatCapsuleSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let onDismiss: () -> Void
    @State private var text: String?
    @State private var error: String?
    @State private var picking = false
    @State private var sending = false

    var body: some View {
        if picking {
            ForwardSheet(host: host, exclude: conversationId, onDismiss: { picking = false }) { targets in send(targets) }
        } else {
            AiSheet {
                AiHeader(title: L("ch_ai_capsule_title"), sub: L("ch_ai_capsule_sub")).padding(.bottom, 12)
                if let error {
                    AiError(text: error)
                } else if text != nil {
                    TextEditor(text: Binding(get: { text ?? "" }, set: { text = String($0.prefix(4000)) }))
                        .font(.dorr(14)).foregroundStyle(ch.ink).scrollContentBackground(.hidden)
                        .frame(minHeight: 160)
                        .padding(10)
                        .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(ch.fieldLine, lineWidth: 1))
                    Text(L("ch_ai_capsule_note")).font(.dorr(11.5)).foregroundStyle(ch.mut).padding(.top, 8)
                    ChPrimaryButton(text: L("ch_ai_capsule_send"), icon: "arrowshape.turn.up.right.fill", enabled: !(text ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !sending) {
                        picking = true
                    }
                    .padding(.top, 14)
                } else {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(28)
                }
            }
            .task {
                do { text = try await AiToolsApi.capsule(conversationId).data?.text ?? "" } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
            }
        }
    }

    private func send(_ targets: [String]) {
        let body = (text ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        sending = true
        picking = false
        Task {
            var sent = 0
            for target in targets {
                if (try? await ChatApi.send(target, ["type": "text", "body": body, "uuid": UUID().uuidString])) != nil { sent += 1 }
            }
            sending = false
            host.showToast(L(sent > 0 ? "ch_ai_capsule_sent" : "ch_error_network"), error: sent == 0)
            if sent > 0 { onDismiss() }
        }
    }
}

/// Every image made or changed with AI in my chats; a tap opens it in its conversation.
struct ChatAiImagesSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onOpen: (String, String) -> Void
    @State private var images: [MessageDto]?
    @State private var error: String?

    var body: some View {
        ChSheetCard {
            AiHeader(title: L("ch_ai_images_title"), sub: L("ch_ai_images_sub")).padding(.bottom, 12)
            if let error {
                AiError(text: error)
            } else if let list = images {
                if list.isEmpty {
                    Text(L("ch_ai_images_empty")).font(.dorr(13.5)).foregroundStyle(ch.mut).padding(.vertical, 24)
                } else {
                    ScrollView {
                        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 6), count: 3), spacing: 6) {
                            ForEach(list, id: \.id) { m in
                                Button { onOpen(m.conversationId, m.id) } label: {
                                    Color.clear.aspectRatio(1, contentMode: .fit)
                                        .overlay { if let url = m.attachments?.first?.url { ChatImage(url: ApiClient.mediaUrl(url)) } }
                                        .background(ch.surfaceMuted)
                                        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .frame(maxHeight: 520)
                }
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(28)
            }
        }
        .task {
            do { images = try await AiToolsApi.aiImages().data ?? [] } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
        }
    }
}
