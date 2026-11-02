import SwiftUI

// androidApp `aichat/AiLanguageSettingsScreen.kt` — the one place a person controls DORR AI's reply language and
// dialect (every chat reply, voice transcript and realtime call reads it). Two modes, like the server: follow my
// language message by message, or always one chosen language (optionally one of its dialect / style variants).

struct AiLanguageSettingsScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var loading = true
    @State private var loadError: String?
    @State private var languages: [AiLanguageDto] = []
    @State private var variants: [AiLanguageVariantDto] = []
    @State private var mode = "follow_input"
    @State private var languageId: Int?
    @State private var variantId: Int?
    @State private var saving = false
    @State private var saveError: String?
    @State private var savedFlash = false

    private func apply(_ data: AiLanguagePreferenceShowDto?) {
        guard let data else { return }
        languages = data.languages ?? []
        variants = data.variants ?? []
        mode = data.preference.responseLanguageMode ?? "follow_input"
        languageId = data.preference.language?.id
        variantId = data.preference.variant?.id
    }

    private func save() {
        if saving { return }
        if mode == "fixed" && languageId == nil {
            saveError = L("ai_language_fixed_requires_language")
            return
        }
        saving = true
        saveError = nil
        Task {
            do {
                _ = try await AiChatApi.updateLanguagePreference(mode: mode, languageId: mode == "fixed" ? languageId : nil, variantId: mode == "fixed" ? variantId : nil)
                savedFlash = true
                // Re-read so the screen shows exactly what the server kept.
                apply(try? await AiChatApi.languagePreference().data)
                Task { try? await Task.sleep(nanoseconds: 2_000_000_000); savedFlash = false }
            } catch {
                saveError = error.apiFailure.message
            }
            saving = false
        }
    }

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onExit)
                Text(L("ai_language_title")).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
                Spacer()
            }
            .padding(.horizontal, 8).padding(.vertical, 8)
            if loading {
                ProgressView().tint(c.red).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if let loadError {
                Text(verbatim: loadError).font(.dorr(13)).foregroundStyle(c.mut).padding(32).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("ai_language_subtitle")).font(.dorr(13)).foregroundStyle(c.mut).padding(.top, 4).padding(.bottom, 18)
                        modeOption(L("ai_language_mode_follow_input"), L("ai_language_mode_follow_input_desc"), "follow_input")
                        modeOption(L("ai_language_mode_fixed"), L("ai_language_mode_fixed_desc"), "fixed").padding(.top, 10)
                        if mode == "fixed" {
                            Text(L("ai_language_select_language")).font(.dorr(13.5, .bold)).foregroundStyle(c.ink).padding(.top, 18).padding(.bottom, 8)
                            ForEach(languages) { lang in
                                pickRow(lang.name, languageId == lang.id) {
                                    if languageId != lang.id { languageId = lang.id; variantId = nil }
                                }
                                .padding(.bottom, 8)
                            }
                            let own = variants.filter { $0.languageId == languageId }
                            if languageId != nil && !own.isEmpty {
                                Text(L("ai_language_select_dialect")).font(.dorr(13.5, .bold)).foregroundStyle(c.ink).padding(.top, 10).padding(.bottom, 8)
                                ForEach(own) { v in pickRow(v.name, variantId == v.id) { variantId = v.id }.padding(.bottom, 8) }
                            }
                        }
                        if let saveError { Text(verbatim: saveError).font(.dorr(12.5)).foregroundStyle(Color(hex: 0xB91C1C)).padding(.top, 20) }
                        Button(action: save) {
                            HStack(spacing: 6) {
                                if saving {
                                    ProgressView().tint(.white).scaleEffect(0.8)
                                } else if savedFlash {
                                    Image(systemName: "checkmark").font(.system(size: 14, weight: .bold))
                                    Text(L("ai_language_saved")).font(.dorr(14, .bold))
                                } else {
                                    Text(L("ai_language_save")).font(.dorr(14, .bold))
                                }
                            }
                            .foregroundStyle(.white)
                            .frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(c.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .disabled(saving)
                        .padding(.top, saveError == nil ? 20 : 10)
                    }
                    .padding(.horizontal, 16).padding(.bottom, 32)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .task {
            do { apply(try await AiChatApi.languagePreference().data) } catch { loadError = error.apiFailure.message }
            loading = false
        }
    }

    private func modeOption(_ title: String, _ desc: String, _ value: String) -> some View {
        let c = wa.ai
        let on = mode == value
        return Button { mode = value } label: {
            HStack(spacing: 12) {
                Image(systemName: on ? "largecircle.fill.circle" : "circle").font(.system(size: 20)).foregroundStyle(on ? c.red : c.mut)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                    Text(verbatim: desc).font(.dorr(12)).foregroundStyle(c.mut).multilineTextAlignment(.leading)
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? c.red : .clear, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    private func pickRow(_ label: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        let c = wa.ai
        return Button(action: action) {
            HStack {
                Text(verbatim: label).font(.dorr(13.5)).foregroundStyle(c.ink)
                Spacer()
                if on {
                    Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white).frame(width: 22, height: 22).background(c.red, in: Circle())
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 11)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(on ? c.red : .clear, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}
