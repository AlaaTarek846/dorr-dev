import PhotosUI
import SwiftUI

// androidApp `chat/ChatThemes.kt` (ChatThemePage) — pick a look for this chat: a live preview on top (wallpaper + two
// bubbles) that changes as you go, the admin's themes, and «make it yours» — a photo from the gallery (dimmed as you
// like) and your own bubble colours. Only you see it. «Dorr» = the app's own look.

private let myBubbleColors = ["#E50914", "#F97316", "#F59E0B", "#10B981", "#0D9488", "#0EA5E9", "#2563EB", "#6366F1", "#7C3AED", "#DB2777", "#111827"]
private let theirBubbleColors = ["#FFFFFF", "#F3F4F6", "#FEF3C7", "#DCFCE7", "#E0F2FE", "#EDE9FE", "#FCE7F3", "#FFE4E6", "#1F2937", "#334155"]

struct ChatThemePage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let id: String
    @State private var conversation: ConversationDto?

    var body: some View {
        ChSettingsPage(title: L("ch_chat_theme"), onBack: { host.pop() }) {
            if let c = conversation {
                ThemePickerBody(host: host, conversation: c) { updated in
                    conversation = updated
                    host.upsert(updated)
                }
            } else {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            }
        }
        .task(id: id) {
            if conversation == nil { conversation = host.find(id) }
            if let fresh = try? await ChatApi.conversation(id).data { conversation = fresh }
        }
    }
}

private struct ThemePickerBody: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversation: ConversationDto
    let onApplied: (ConversationDto) -> Void
    @State private var themes: [ChatThemeDto]?
    @State private var picked: Int?
    /// My own look as it stands on the server (the photo is uploaded right away)…
    @State private var custom: CustomThemeDto?
    /// …and the colour / dim changes not applied yet.
    @State private var mine: String?
    @State private var theirs: String?
    @State private var dim: Double = 25
    @State private var saving = false
    @State private var uploading = false
    @State private var item: PhotosPickerItem?
    @State private var picking = false
    @State private var started = false

    var body: some View {
        let list = themes
        // What «no pick» shows: the admin's default when there is one, else Dorr's look.
        let fallback = list?.first { $0.isDefault == true }
        let base = picked.flatMap { id in list?.first { $0.id == id } } ?? fallback
        let hasCustom = custom?.wallpaper != nil || mine != nil || theirs != nil || custom?.backgroundColor != nil
        // The preview: my look over the theme, exactly as the server will draw it.
        let preview: ChatThemeDto? = hasCustom ? ChatThemeDto(
            id: 0, name: nil, wallpaper: custom?.wallpaper ?? base?.wallpaper, backgroundColor: custom?.backgroundColor ?? base?.backgroundColor,
            senderColor: mine ?? base?.senderColor, receiverColor: theirs ?? base?.receiverColor, isDark: base?.isDark ?? false,
            isDefault: false, isCustom: true, dim: custom?.wallpaper != nil ? Int(dim) : 0
        ) : base
        let saved = conversation.theme?.custom
        let changed = picked != conversation.theme?.themeId || mine != saved?.senderColor || theirs != saved?.receiverColor
            || (custom?.wallpaper != nil && Int(dim) != (saved?.dim ?? 25))
        let canReset = conversation.theme?.themeId != nil || saved != nil || custom != nil
        VStack(spacing: 0) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_chat_theme_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.horizontal, 16).padding(.vertical, 8)
                    ZStack {
                        ThemePreview(theme: preview).frame(height: 168)
                        if uploading {
                            Color.black.opacity(0.35).clipShape(RoundedRectangle(cornerRadius: 18))
                            ProgressView().tint(.white)
                        }
                    }
                    .padding(.horizontal, 16)
                    sectionTitle(L("ch_theme_wallpaper"))
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 10) {
                            addPhotoSwatch(selected: custom?.wallpaper != nil).chStagger(0)
                            if let list {
                                swatch(nil, L("ch_theme_dorr"), selected: !hasCustom && picked == nil && fallback == nil) { picked = nil; mine = nil; theirs = nil }.chStagger(1)
                                ForEach(Array(list.enumerated()), id: \.element.id) { i, t in
                                    swatch(t, t.name ?? "", selected: !hasCustom && (picked ?? fallback?.id) == t.id) { picked = t.id; mine = nil; theirs = nil }.chStagger(i + 2)
                                }
                            } else {
                                ProgressView().tint(ch.red).frame(width: 62, height: 82)
                            }
                        }
                        .padding(.horizontal, 16)
                    }
                    if custom?.wallpaper != nil {
                        VStack(spacing: 4) {
                            HStack(spacing: 6) {
                                Text(L("ch_theme_dim")).font(.dorr(15, .heavy)).foregroundStyle(ch.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                                iconButton("arrow.triangle.2.circlepath", L("ch_theme_change_photo"), enabled: !uploading) { picking = true }
                                iconButton("trash", L("ch_theme_remove_photo"), enabled: !saving) {
                                    save(["custom_theme": .object(["wallpaper": .null])])
                                }
                            }
                            HStack(spacing: 6) {
                                Image(systemName: "circle.lefthalf.filled").font(.system(size: 17)).foregroundStyle(ch.mut)
                                Slider(value: $dim, in: 0...80).tint(ch.red)
                                Text(verbatim: "\(Int(dim))%").font(.dorr(12, .bold)).foregroundStyle(ch.mut).frame(width: 38)
                            }
                        }
                        .padding(.horizontal, 16).padding(.top, 16)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    }
                    sectionTitle(L("ch_theme_message_colors"))
                    colorRow(L("ch_theme_my_bubbles"), myBubbleColors, mine) { mine = $0 }
                    colorRow(L("ch_theme_their_bubbles"), theirBubbleColors, theirs) { theirs = $0 }
                }
                .padding(.bottom, 8)
                .animation(.easeOut(duration: 0.25), value: custom?.wallpaper != nil)
            }
            HStack(spacing: 10) {
                ChPrimaryButton(text: L("ch_apply"), icon: "checkmark", enabled: !saving && !uploading && list != nil && changed) {
                    let keep = custom?.wallpaper != nil || mine != nil || theirs != nil
                    save([
                        "theme_id": picked.map { .int($0) } ?? .null,
                        "custom_theme": keep ? .object(["sender_color": mine.map { .string($0) } ?? .null, "receiver_color": theirs.map { .string($0) } ?? .null, "dim": .int(Int(dim))]) : .null,
                    ])
                }
                Button { save(["theme_id": .null, "custom_theme": .null]) } label: {
                    HStack(spacing: 6) {
                        Image(systemName: "paintbrush.pointed").font(.system(size: 15))
                        Text(L("ch_theme_reset")).font(.dorr(12.5, .heavy)).lineLimit(1)
                    }
                    .foregroundStyle(ch.red)
                    .frame(maxWidth: .infinity, minHeight: 48)
                    .background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.red, lineWidth: 1.5))
                    .opacity(!saving && canReset ? 1 : 0.45)
                }
                .buttonStyle(.plain)
                .disabled(saving || !canReset)
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
        }
        .task { themes = (try? await ChatApi.themes().data) ?? [] }
        .onAppear {
            guard !started else { return }
            started = true
            picked = conversation.theme?.themeId
            custom = conversation.theme?.custom
            mine = custom?.senderColor
            theirs = custom?.receiverColor
            dim = Double(custom?.dim ?? 25)
        }
        .photosPicker(isPresented: $picking, selection: $item, matching: .images)
        .onChange(of: item) { picked in
            guard let picked else { return }
            item = nil
            Task { await upload(picked) }
        }
    }

    private func applyServer(_ updated: ConversationDto) {
        custom = updated.theme?.custom
        picked = updated.theme?.themeId
        mine = custom?.senderColor
        theirs = custom?.receiverColor
        dim = Double(custom?.dim ?? 25)
        onApplied(updated)
    }

    private func upload(_ picked: PhotosPickerItem) async {
        uploading = true
        defer { uploading = false }
        guard let data = try? await picked.loadTransferable(type: Data.self), let image = UIImage(data: data) else { return }
        let budget = ChatPrefs.shared.photoBudget()
        let side = max(image.size.width, image.size.height)
        let scale = side > budget.side ? budget.side / side : 1
        let size = CGSize(width: image.size.width * scale, height: image.size.height * scale)
        let resized = UIGraphicsImageRenderer(size: size).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
        guard let jpeg = resized.jpegData(compressionQuality: budget.quality) else { return }
        do {
            if let updated = try await ChatApi.uploadWallpaper(conversation.id, image: ChatFile(field: "image", filename: "wallpaper.jpg", mimeType: "image/jpeg", data: jpeg)).data {
                applyServer(updated)
            }
        } catch {
            host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
        }
    }

    /// Keeps nulls: `theme_id: null` goes back to Dorr's look.
    private func save(_ body: [String: JSONValue]) {
        saving = true
        Task {
            do {
                let envelope: ApiEnvelope<ConversationDto> = try await ApiClient.snake("mobile/v1/chat/conversations/\(conversation.id)/settings", method: "PATCH", body: body)
                if let updated = envelope.data { applyServer(updated) }
            } catch {
                if let m = error.apiFailure.message { host.showToast(m, error: true) }
            }
            saving = false
        }
    }

    private func sectionTitle(_ title: String) -> some View {
        Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(ch.ink).lineLimit(1).padding(.horizontal, 16).padding(.top, 16).padding(.bottom, 10)
    }

    private func iconButton(_ icon: String, _ label: String, enabled: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 13))
                Text(verbatim: label).font(.dorr(11, .bold)).lineLimit(1)
            }
            .foregroundStyle(ch.red).padding(8)
            .background(ch.red.opacity(enabled ? 0.12 : 0.05), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }

    /// A row of colour dots; the first one means: use the theme's colour.
    private func colorRow(_ title: String, _ colors: [String], _ selected: String?, _ onPick: @escaping (String?) -> Void) -> some View {
        HStack(spacing: 0) {
            Text(verbatim: title).font(.dorr(13, .heavy)).foregroundStyle(ch.ink).lineLimit(1).frame(width: 72, alignment: .leading).padding(.trailing, 8)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    colorDot(nil, selected == nil) { onPick(nil) }
                    ForEach(Array(colors.enumerated()), id: \.offset) { i, hex in
                        colorDot(hex, selected?.lowercased() == hex.lowercased()) { onPick(hex) }.chStagger(i)
                    }
                }
                .padding(.trailing, 16)
            }
        }
        .padding(.leading, 16).padding(.vertical, 6)
    }

    private func colorDot(_ hex: String?, _ selected: Bool, _ action: @escaping () -> Void) -> some View {
        let color = hexColor(hex)
        return Button(action: action) {
            ZStack {
                Circle().fill(color ?? ch.surfaceMuted).overlay(Circle().stroke(ch.line, lineWidth: 1))
                if color == nil {
                    Image(systemName: "paintbrush.pointed").font(.system(size: 14)).foregroundStyle(ch.mut)
                } else if selected {
                    Image(systemName: "checkmark").font(.system(size: 14, weight: .bold)).foregroundStyle(color!.isLight ? Color(hex: 0x111928) : .white)
                }
            }
            .padding(selected ? 4 : 2)
            .frame(width: 36, height: 36)
            .overlay(Circle().stroke(ch.red, lineWidth: selected ? 3 : 0))
            .animation(.spring(response: 0.3, dampingFraction: 0.5), value: selected)
        }
        .buttonStyle(.plain)
    }

    private func addPhotoSwatch(selected: Bool) -> some View {
        Button { picking = true } label: {
            VStack(spacing: 6) {
                ZStack {
                    if let w = custom?.wallpaper {
                        ChatImage(url: ApiClient.mediaUrl(w)).frame(width: 62, height: 82).clipShape(RoundedRectangle(cornerRadius: 12))
                    } else if uploading {
                        ProgressView().tint(ch.red)
                    } else {
                        VStack(spacing: 6) {
                            Image(systemName: "plus").font(.system(size: 12, weight: .bold)).foregroundStyle(.white).frame(width: 22, height: 22).background(ch.red, in: Circle())
                            Text(L("ch_theme_add_photo")).font(.dorr(10, .heavy)).foregroundStyle(ch.red).multilineTextAlignment(.center).lineLimit(2).padding(.horizontal, 4)
                        }
                    }
                }
                .frame(width: 62, height: 82)
                .overlay {
                    if custom?.wallpaper == nil {
                        RoundedRectangle(cornerRadius: 12).stroke(ch.red, style: StrokeStyle(lineWidth: 1.6, dash: [10, 6]))
                    }
                }
                .padding(selected ? 4 : 0)
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(ch.red, lineWidth: selected ? 3 : 0))
            }
            .frame(width: 68)
        }
        .buttonStyle(.plain)
        .disabled(uploading)
    }

    private func swatch(_ theme: ChatThemeDto?, _ label: String, selected: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 6) {
                ZStack(alignment: .topTrailing) {
                    ZStack {
                        if let theme {
                            if let w = theme.wallpaper { ChatImage(url: ApiClient.mediaUrl(w)) } else { hexColor(theme.backgroundColor) ?? ch.surfaceMuted }
                        } else {
                            ChWallpaper()
                        }
                        VStack(spacing: 4) {
                            Spacer()
                            RoundedRectangle(cornerRadius: 4).fill(hexColor(theme?.receiverColor) ?? ch.surface).frame(width: 24, height: 7).frame(maxWidth: .infinity, alignment: .leading)
                            RoundedRectangle(cornerRadius: 4).fill(hexColor(theme?.senderColor) ?? ch.red).frame(width: 24, height: 7).frame(maxWidth: .infinity, alignment: .trailing)
                        }
                        .padding(6)
                    }
                    .frame(width: 62, height: 82)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                    if selected {
                        Image(systemName: "checkmark").font(.system(size: 9, weight: .bold)).foregroundStyle(.white).frame(width: 16, height: 16).background(ch.red, in: Circle())
                            .padding(4).transition(.scale)
                    }
                }
                .padding(selected ? 4 : 0)
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(ch.red, lineWidth: selected ? 3 : 0))
                Text(verbatim: label).font(.dorr(11.5, selected ? .heavy : .medium)).foregroundStyle(selected ? ch.ink : ch.mut).lineLimit(1)
            }
            .frame(width: 68)
            .animation(.spring(response: 0.3, dampingFraction: 0.5), value: selected)
        }
        .buttonStyle(.plain)
    }
}

/// A mini chat: the wallpaper with one bubble each way — what the conversation will look like.
private struct ThemePreview: View {
    @ObservedObject private var ch = Ch.shared
    let theme: ChatThemeDto?

    var body: some View {
        let outColor = hexColor(theme?.senderColor) ?? ch.red
        let inColor = hexColor(theme?.receiverColor) ?? ch.surface
        ZStack {
            ThemedWallpaper(theme: theme)
            VStack(spacing: 6) {
                Spacer()
                ChChip(text: L("ch_today"))
                bubble(L("ch_theme_sample_in"), inColor, mine: false)
                bubble(L("ch_theme_sample_out"), outColor, mine: true)
            }
            .padding(12)
        }
        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.ink.opacity(0.12), lineWidth: 1))
        .shadow(color: .black.opacity(0.15), radius: 6, y: 2)
        .animation(.easeInOut(duration: 0.38), value: theme)
    }

    private func bubble(_ text: String, _ color: Color, mine: Bool) -> some View {
        Text(verbatim: text).font(.dorr(12, .semibold)).foregroundStyle(color.isLight ? Color(hex: 0x111928) : .white)
            .padding(.horizontal, 11).padding(.vertical, 7)
            .background(color, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .shadow(color: .black.opacity(0.1), radius: 2, y: 1)
            .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
    }
}
