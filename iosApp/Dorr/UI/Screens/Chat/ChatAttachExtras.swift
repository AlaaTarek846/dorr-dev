import SwiftUI

// androidApp `chat/ChatInteractiveUi.kt` (InteractiveComposerSheet), `ChatAiImageUi.kt` (ChatAiImageSheet) and
// Composer's `TransferPicker` — reply buttons or a list to choose from (WhatsApp list 123–125); images with DORR AI in
// the chat (spec 50, 51): a new one from a description, or an image in the chat changed as described, a draft only I
// see, sent as an image marked «Made with AI»; and one of my transfers shared as a receipt card.

// MARK: - reply buttons / a list

struct InteractiveComposerSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onSend: (_ body: String, _ extra: [String: JSONAny]) -> Void
    @State private var body_ = ""
    @State private var list = false
    @State private var options = ["", ""]
    @State private var descriptions = ["", ""]
    @State private var button = ""
    @State private var footer = ""

    var body: some View {
        let max = list ? 10 : 3
        let titleMax = list ? 24 : 20
        let filled = options.map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
        let ready = !body_.trimmingCharacters(in: .whitespaces).isEmpty && !filled.isEmpty && filled.count <= max && Set(filled.map { $0.lowercased() }).count == filled.count
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 12) {
                        ChIconBadge(icon: "rectangle.and.hand.point.up.left.fill", color: Color(hex: 0x0EA5E9), iconTint: ch.red)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("ia_new")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                            Text(L("ia_new_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                        }
                    }
                    // Buttons or a list.
                    HStack(spacing: 0) {
                        ForEach([(false, "ia_kind_buttons"), (true, "ia_kind_list")], id: \.0) { isList, label in
                            let on = list == isList
                            Button {
                                list = isList
                                // Down to 3 when going back to buttons.
                                while !isList && options.count > 3 { options.removeLast(); descriptions.removeLast() }
                            } label: {
                                Text(L(label)).font(.dorr(14, .bold)).foregroundStyle(on ? .white : ch.ink).frame(maxWidth: .infinity).padding(.vertical, 9)
                                    .background(on ? ch.red : .clear, in: RoundedRectangle(cornerRadius: 11, style: .continuous))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(4)
                    .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .padding(.top, 14)
                    ChField(placeholder: L("ia_body_hint"), text: $body_, icon: "text.alignleft", lines: 1...5, limit: 1000).padding(.top, 12)
                    if list { ChField(placeholder: L("ia_button_hint"), text: $button, icon: "list.bullet", limit: 20).padding(.top, 8) }
                    Text(L(list ? "ia_choices" : "ia_buttons")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.leading, 4).padding(.top, 12).padding(.bottom, 6)
                    ForEach(options.indices, id: \.self) { i in
                        VStack(spacing: 4) {
                            HStack(spacing: 6) {
                                ChField(placeholder: L("ia_option_hint", i + 1), text: Binding(get: { options.indices.contains(i) ? options[i] : "" }, set: { if options.indices.contains(i) { options[i] = String($0.prefix(titleMax)) } }),
                                        icon: list ? "circle" : "arrowshape.turn.up.left", limit: titleMax)
                                if options.count > 1 {
                                    Button {
                                        options.remove(at: i)
                                        descriptions.remove(at: i)
                                    } label: {
                                        Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(ch.danger).frame(width: 34, height: 34).background(ch.danger.opacity(0.12), in: Circle())
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                            if list {
                                ChField(placeholder: L("ia_description_hint"), text: Binding(get: { descriptions.indices.contains(i) ? descriptions[i] : "" }, set: { if descriptions.indices.contains(i) { descriptions[i] = String($0.prefix(72)) } }),
                                        icon: "text.alignleft", limit: 72)
                                    .padding(.leading, 24).padding(.trailing, 40)
                            }
                        }
                        .padding(.vertical, 4)
                        .chStagger(i)
                    }
                    if options.count < max {
                        Button {
                            options.append("")
                            descriptions.append("")
                        } label: {
                            HStack(spacing: 6) {
                                Image(systemName: "plus").font(.system(size: 17, weight: .semibold))
                                Text(L(list ? "ia_add_choice" : "ia_add_button")).font(.dorr(14, .heavy))
                            }
                            .foregroundStyle(ch.red).padding(.horizontal, 12).padding(.vertical, 10)
                            .background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                    ChField(placeholder: L("ia_footer_hint"), text: $footer, icon: "text.alignleft", limit: 60).padding(.top, 8)
                    ChPrimaryButton(text: L("ch_send"), icon: "paperplane.fill", enabled: ready, action: send).padding(.top, 14)
                }
            }
            .frame(maxHeight: 640)
        }
    }

    private func send() {
        let kept = options.indices.filter { !options[$0].trimmingCharacters(in: .whitespaces).isEmpty }
        var extra: [String: JSONAny] = [
            "interactive_kind": .string(list ? "list" : "buttons"),
            "interactive_options": .from(kept.map { options[$0].trimmingCharacters(in: .whitespaces) }),
        ]
        if list {
            extra["interactive_descriptions"] = .from(kept.map { descriptions[$0].trimmingCharacters(in: .whitespaces) })
            let b = button.trimmingCharacters(in: .whitespaces)
            if !b.isEmpty { extra["interactive_button"] = .string(b) }
        }
        let f = footer.trimmingCharacters(in: .whitespaces)
        if !f.isEmpty { extra["interactive_footer"] = .string(f) }
        onSend(body_.trimmingCharacters(in: .whitespacesAndNewlines), extra)
        onDismiss()
    }
}

// MARK: - images with DORR AI

private struct ImageStyle {
    let key: String
    let label: String
    let colors: [Color]
}

private let imageStyles = [
    ImageStyle(key: "realistic", label: "ch_ai_style_realistic", colors: [Color(hex: 0x94A3B8), Color(hex: 0x334155)]),
    ImageStyle(key: "illustration", label: "ch_ai_style_illustration", colors: [Color(hex: 0xFBBF24), Color(hex: 0xEC4899)]),
    ImageStyle(key: "anime", label: "ch_ai_style_anime", colors: [Color(hex: 0xF9A8D4), Color(hex: 0x8B5CF6)]),
    ImageStyle(key: "3d", label: "ch_ai_style_3d", colors: [Color(hex: 0x67E8F9), Color(hex: 0x2563EB)]),
    ImageStyle(key: "oil", label: "ch_ai_style_oil", colors: [Color(hex: 0xFDBA74), Color(hex: 0xB45309)]),
    ImageStyle(key: "minimal", label: "ch_ai_style_minimal", colors: [Color(hex: 0xE5E7EB), Color(hex: 0x9CA3AF)]),
]


/// Write → DORR AI draws (a soft moving canvas) → the result only I see: send it with a caption, try again, or change
/// the words. Every image counts against the plan's daily images; a locked plan offers the plans.
struct ChatAiImageSheet: View {
    @ObservedObject var state: ConversationState
    @ObservedObject private var ch = Ch.shared
    let source: MessageDto?
    let onDismiss: () -> Void
    @State private var prompt = ""
    @State private var style: String?
    @State private var caption = ""
    @State private var draft: AiImageDraftDto?
    @State private var making = false
    @State private var sending = false
    @State private var error: String?
    @State private var quota: AiImageQuotaDto?

    private var editing: Bool { source != nil }

    var body: some View {
        let locked = quota?.allowed == false && draft == nil && !making
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 10) {
                        Image(systemName: "sparkles").font(.system(size: 19)).foregroundStyle(.white)
                            .frame(width: 42, height: 42).background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 14))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L(editing ? "ch_ai_image_edit_title" : "ch_ai_image_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                            Text(L("ch_ai_image_sub")).font(.dorr(12)).foregroundStyle(ch.mut)
                        }
                        Spacer(minLength: 0)
                        quotaChip
                    }
                    .padding(.bottom, 16)
                    Group {
                        if locked {
                            lockedCard
                        } else if making {
                            MakingCanvas()
                        } else if let d = draft {
                            ChatImage(url: ApiClient.mediaUrl(d.url)).aspectRatio(1, contentMode: .fill).frame(maxWidth: .infinity)
                                .background(ch.surfaceMuted).clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
                        } else {
                            writeStage
                        }
                    }
                    .transition(.opacity)
                    .animation(.easeOut(duration: 0.22), value: making)
                    if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(ch.danger).padding(.top, 10) }
                    actions(locked).padding(.top, 16)
                    HStack(spacing: 8) {
                        Image(systemName: "info.circle.fill").font(.system(size: 14)).foregroundStyle(.white)
                        Text(L("ch_ai_image_quota_note")).font(.dorr(11)).foregroundStyle(.white).frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(.horizontal, 10).padding(.vertical, 8)
                    .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.top, 10)
                }
            }
            .frame(maxHeight: 700)
        }
        .task { await refreshQuota() }
    }

    private var quotaChip: some View {
        Group {
            if let q = quota {
                let lockedNow = q.allowed != true
                let text = lockedNow ? L("ch_ai_image_locked_chip") : q.limit == nil ? L("ch_ai_image_unlimited") : L("ch_ai_image_left", q.remaining ?? 0)
                HStack(spacing: 4) {
                    Image(systemName: lockedNow ? "lock.fill" : "sparkles").font(.system(size: 11))
                    Text(verbatim: text).font(.dorr(11.5, .bold))
                }
                .foregroundStyle(lockedNow ? ch.danger : ch.red)
                .padding(.horizontal, 10).padding(.vertical, 5)
                .background((lockedNow ? ch.danger : ch.red).opacity(lockedNow ? 0.12 : 0.1), in: Capsule())
            }
        }
    }

    /// Why images are locked now, in the person's words.
    private var lockedText: String {
        switch quota?.reason {
        case "not_in_plan": return L("ch_ai_image_not_in_plan")
        case "daily_limit_reached": return L("ch_ai_image_limit_reached", quota?.limit ?? 0)
        case "trial_ended": return L("ch_ai_image_trial_ended")
        case "blocked": return L("ch_ai_image_blocked")
        default: return L("ch_ai_image_no_plan")
        }
    }

    private var lockedCard: some View {
        VStack(spacing: 12) {
            Image(systemName: "lock.fill").font(.system(size: 22)).foregroundStyle(.white).frame(width: 52, height: 52).background(Color.white.opacity(0.15), in: Circle())
            Text(verbatim: lockedText).font(.dorr(14, .semibold)).foregroundStyle(.white).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity).padding(22)
        .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }

    private func sectionLabel(_ text: String, _ icon: String) -> some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 13)).foregroundStyle(ch.red)
            Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(ch.ink)
        }
    }

    private var writeStage: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let url = source?.attachments?.first?.url {
                HStack(spacing: 10) {
                    ChatImage(url: ApiClient.mediaUrl(url)).frame(width: 54, height: 54).clipShape(RoundedRectangle(cornerRadius: 14))
                    Text(L("ch_ai_image_source")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                }
                .padding(.bottom, 10)
            }
            ChField(placeholder: L(editing ? "ch_ai_image_edit_hint" : "ch_ai_image_hint"), text: $prompt, lines: 3...6, limit: 1000)
            sectionLabel(L("ch_ai_image_ideas"), "lightbulb.fill").padding(.top, 10).padding(.bottom, 8)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(La(editing ? "ch_ai_image_edit_ideas" : "ch_ai_image_ideas"), id: \.self) { idea in
                        Button { prompt = idea } label: {
                            Text(verbatim: idea).font(.dorr(12.5)).foregroundStyle(ch.ink).lineLimit(1).padding(.horizontal, 12).padding(.vertical, 7).background(ch.surfaceMuted, in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            if !editing {
                sectionLabel(L("ch_ai_image_style"), "paintpalette.fill").padding(.top, 12).padding(.bottom, 8)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach(imageStyles, id: \.key) { s in
                            let on = style == s.key
                            Button { style = on ? nil : s.key } label: {
                                VStack(spacing: 3) {
                                    ZStack {
                                        RoundedRectangle(cornerRadius: 12, style: .continuous).fill(LinearGradient(colors: s.colors, startPoint: .topLeading, endPoint: .bottomTrailing))
                                        if on { Image(systemName: "sparkles").font(.system(size: 14)).foregroundStyle(.white) }
                                    }
                                    .frame(width: 42, height: 42)
                                    .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(on ? ch.red : .clear, lineWidth: 2))
                                    Text(L(s.label)).font(.dorr(10, on ? .bold : .medium)).foregroundStyle(on ? ch.red : ch.mut)
                                }
                                .padding(2)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func actions(_ locked: Bool) -> some View {
        if locked {
            sheetButton(L("ai_plan_required_subscribe"), "lock.fill", primary: true) { AiPlanGate.shared.offer(lockedText) }
        } else if draft != nil {
            ChField(placeholder: L("ch_ai_image_caption"), text: $caption, limit: 4000)
            HStack(spacing: 8) {
                sheetButton(L("ch_ai_image_change"), "pencil", primary: false) { draft = nil }
                sheetButton(L("ch_ai_image_again"), "arrow.clockwise", primary: false, enabled: quota?.remaining != 0) { draft = nil; make() }
            }
            .padding(.top, 12)
            sheetButton(L("ch_ai_image_send"), "paperplane.fill", primary: true, busy: sending, action: send).padding(.top, 8)
        } else if !making {
            sheetButton(L(editing ? "ch_ai_image_edit_go" : "ch_ai_image_go"), "sparkles", primary: true, enabled: !prompt.trimmingCharacters(in: .whitespaces).isEmpty, action: make)
        }
    }

    private func sheetButton(_ text: String, _ icon: String, primary: Bool, busy: Bool = false, enabled: Bool = true, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 7) {
                if busy { ProgressView().tint(.white) } else {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                    Text(verbatim: text).font(.dorr(14, .bold))
                }
            }
            .foregroundStyle(primary ? .white : ch.ink.opacity(enabled ? 1 : 0.4))
            .frame(maxWidth: .infinity).padding(.vertical, 14)
            .background(primary ? AnyShapeStyle(LinearGradient(colors: [ch.red, ch.red.opacity(0.85)], startPoint: .leading, endPoint: .trailing).opacity(enabled ? 1 : 0.4)) : AnyShapeStyle(ch.surfaceMuted),
                        in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(!enabled || busy)
    }

    private func refreshQuota() async { if let q = try? await AiToolsApi.imageQuota().data { quota = q } }

    private func make() {
        let text = prompt.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !making else { return }
        making = true
        error = nil
        Task {
            do {
                if let source { draft = try await AiToolsApi.editImage(source.id, prompt: text).data } else {
                    draft = try await AiToolsApi.generateImage(state.id, prompt: text, style: style).data
                }
            } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
            making = false
            await refreshQuota()
        }
    }

    private func send() {
        guard let current = draft, !sending else { return }
        sending = true
        error = nil
        Task {
            do {
                let c = caption.trimmingCharacters(in: .whitespacesAndNewlines)
                if var sent = try await AiToolsApi.sendImage(state.id, draft: current.draft, body: c.isEmpty ? nil : c, uuid: UUID().uuidString).data {
                    sent.isMine = true
                    sent.status = "sent"
                    state.replaceMessage(sent.id, UiMessage(dto: sent))
                }
                onDismiss()
            } catch { self.error = error.apiFailure.message ?? L("ch_error_network") }
            sending = false
        }
    }
}

/// A soft, moving canvas with rotating hints while the image is being drawn.
private struct MakingCanvas: View {
    @State private var hint = 0
    @State private var spin = false
    @State private var shift = false

    var body: some View {
        let hints = La("ch_ai_image_making")
        ZStack {
            LinearGradient(colors: [Ch.shared.red.opacity(0.35), Ch.shared.red.opacity(0.12), Ch.shared.surfaceMuted],
                           startPoint: shift ? .topLeading : .bottomLeading, endPoint: shift ? .bottomTrailing : .topTrailing)
            VStack(spacing: 14) {
                Image(systemName: "sparkles").font(.system(size: 28)).foregroundStyle(Ch.shared.red).rotationEffect(.degrees(spin ? 360 : 0))
                    .frame(width: 64, height: 64).background(Color.white.opacity(0.55), in: Circle())
                if !hints.isEmpty {
                    Text(verbatim: hints[hint % hints.count]).font(.dorr(14, .bold)).foregroundStyle(Ch.shared.ink).id(hint).transition(.opacity)
                }
            }
        }
        .aspectRatio(1, contentMode: .fit)
        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
        .onAppear {
            withAnimation(.linear(duration: 3).repeatForever(autoreverses: false)) { spin = true }
            withAnimation(.linear(duration: 2.4).repeatForever(autoreverses: true)) { shift = true }
        }
        .task {
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 2_200_000_000)
                withAnimation { hint += 1 }
            }
        }
    }
}

// MARK: - a transfer receipt

/// My recent outgoing transfers, to share one as a receipt card.
struct TransferPicker: View {
    @ObservedObject private var ch = Ch.shared
    let onPick: (WalletTransactionDto) -> Void
    @State private var list: [WalletTransactionDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false

    private func loadPage(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let envelope = try? await WalletApi.transactions(page: next, perPage: 15, type: "transfer_out")
        let chunk = envelope?.data ?? []
        if reset {
            list = chunk
        } else {
            let known = Set((list ?? []).map(\.uuid))
            list = (list ?? []) + chunk.filter { !known.contains($0.uuid) }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }

    var body: some View {
        ChSheetCard {
            Text(L("ch_pick_transfer")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 12)
            if let items = list {
                if items.isEmpty {
                    Text(L("ch_no_transfers")).font(.dorr(14)).foregroundStyle(ch.mut).padding(.vertical, 24)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 8) {
                            ForEach(Array(items.enumerated()), id: \.element.uuid) { i, tx in
                                Button { onPick(tx) } label: {
                                    HStack(spacing: 10) {
                                        Image(systemName: "banknote.fill").font(.system(size: 17)).foregroundStyle(.white).frame(width: 40, height: 40).background(ch.headerBrush, in: Circle())
                                        VStack(alignment: .leading, spacing: 1) {
                                            Text(verbatim: tx.counterparty?.name ?? tx.typeLabel).font(.dorr(14, .bold)).foregroundStyle(ch.ink)
                                            Text(verbatim: listTime(tx.createdAt)).font(.dorr(12)).foregroundStyle(ch.mut)
                                        }
                                        Spacer()
                                        Text(verbatim: formatMinor(tx.amountMinor, nil)).font(.dorr(15, .heavy)).foregroundStyle(ch.red).environment(\.layoutDirection, .leftToRight)
                                    }
                                    .padding(12)
                                    .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                                }
                                .buttonStyle(.plain)
                                .onAppear { if i >= items.count - 3 { Task { await loadPage(reset: false) } } }
                            }
                            if loadingMore { ProgressView().tint(ch.red).padding(12) }
                        }
                    }
                    .frame(maxHeight: 420)
                }
            } else {
                VStack(spacing: 8) { ForEach(0..<3, id: \.self) { _ in WaSkeleton().frame(height: 62) } }
            }
        }
        .task { await loadPage(reset: true) }
    }
}
