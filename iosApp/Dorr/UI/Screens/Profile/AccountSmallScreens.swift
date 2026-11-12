import StoreKit
import SwiftUI
import UIKit

// androidApp `profile/ContactUsSheet.kt`, `FaqSheet.kt`, `NotificationSettingsScreen.kt`,
// `InviteFriendsScreen.kt`, `RateAppScreen.kt`.

/// androidApp `SettingsPrimaryButton` — 54 tall, rounded 18, brand; 55 % brand while disabled.
struct SettingsPrimaryButton: View {
    @Environment(\.wa) private var wa
    let text: String
    var enabled = true
    var loading = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if loading { ProgressView().tint(.white) } else { Text(verbatim: text).font(.dorr(16, .heavy)) }
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, minHeight: 54)
            .background(wa.red.opacity(enabled && !loading ? 1 : 0.55), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.38), radius: 12, y: 4)
        }
        .buttonStyle(.plain)
        .allowsHitTesting(enabled && !loading)
    }
}

/// A bottom panel over a 45 % scrim, the pink backdrop behind it (the Contact / FAQ "sheets" on Android are
/// dialogs drawn this way, not system sheets).
struct BottomPanel<Content: View>: View {
    let onDismiss: () -> Void
    var maxHeight: CGFloat?
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack {
            Spacer(minLength: 0)
            content()
                .frame(maxWidth: .infinity)
                .frame(maxHeight: maxHeight)
                .background(PinkBackdrop())
                .clipShape(UnevenRoundedRectangleCompat(topRadius: 22))
                .contentShape(Rectangle())
                .onTapGesture {}
        }
        .ignoresSafeArea(edges: .bottom)
    }
}

// MARK: - Contact us

private let contactPhone = "+96522200000"
private let contactWhatsapp = "96522200000"
private let contactEmail = "support@dorr.app"

struct ContactUsSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void

    var body: some View {
        BottomPanel(onDismiss: onDismiss) {
            VStack(spacing: 8) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "phone.fill")
                    SettingsScreenTitle(title: L("contact_title"))
                    Spacer(minLength: 0)
                }
                .padding(.bottom, 4)
                ContactTile(icon: "phone.fill", label: L("contact_phone"), value: contactPhone) { open("tel:\(contactPhone)") }
                ContactTile(icon: "bubble.left.fill", label: L("contact_whatsapp"), value: contactWhatsapp) { open("https://wa.me/\(contactWhatsapp)") }
                ContactTile(icon: "envelope.fill", label: L("contact_email"), value: contactEmail) { open("mailto:\(contactEmail)") }
            }
            .padding(.horizontal, 14)
            .padding(.top, 18)
            .padding(.bottom, 24 + safeBottom)
        }
    }

    private func open(_ link: String) {
        if let url = URL(string: link) { UIApplication.shared.open(url) }
    }
}

/// The phone's bottom inset (home indicator), for panels drawn edge to edge.
var safeBottom: CGFloat {
    UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first?.safeAreaInsets.bottom ?? 0
}

private struct ContactTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                PinkIcon(icon: icon)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                    Text(verbatim: value).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9))
            }
            .padding(12)
            .settingsSurface(14)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - FAQ

@MainActor
struct FaqSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    @State private var faqs: [FaqDto]?
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var searchQuery = ""
    @State private var openIds: Set<Int> = []

    private var filtered: [FaqDto] {
        let list = faqs ?? []
        let q = searchQuery.trimmingCharacters(in: .whitespaces).lowercased()
        guard !q.isEmpty else { return list }
        return list.filter { $0.question.lowercased().contains(q) || $0.answer.lowercased().contains(q) }
    }

    var body: some View {
        BottomPanel(onDismiss: onDismiss, maxHeight: 620) {
            VStack(spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "questionmark.circle.fill")
                    SettingsScreenTitle(title: L("faq_title"))
                    Spacer(minLength: 0)
                    Button(action: onDismiss) {
                        Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 28, height: 28).background((wa.night ? wa.ink : AppColors.textPrimary).opacity(0.06), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
                .padding(.bottom, 12)

                if !isLoading && errorMessage == nil && !(faqs ?? []).isEmpty {
                    HStack(spacing: 8) {
                        Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(wa.settingsMut)
                        ZStack(alignment: .leading) {
                            if searchQuery.isEmpty { Text(L("faq_search_hint")).font(.dorr(13.5)).foregroundStyle(wa.settingsMut.opacity(0.7)) }
                            TextField("", text: $searchQuery).font(.dorr(13.5)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).tint(wa.red)
                        }
                        if !searchQuery.isEmpty {
                            Button { searchQuery = "" } label: { Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.settingsMut) }
                                .buttonStyle(.plain)
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 10)
                    .settingsSurface(12)
                    .padding(.bottom, 12)
                }

                Group {
                    if isLoading {
                        VStack(spacing: 10) {
                            ProgressView().tint(wa.red)
                            Text(L("content_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, minHeight: 180)
                    } else if errorMessage != nil {
                        VStack(spacing: 10) {
                            Text(L("content_error")).font(.dorr(14, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                            Button { Task { await load() } } label: {
                                HStack(spacing: 6) {
                                    Image(systemName: "arrow.clockwise").font(.system(size: 14, weight: .semibold))
                                    Text(L("content_retry")).font(.dorr(13, .bold))
                                }
                                .foregroundStyle(wa.red).padding(.horizontal, 14).padding(.vertical, 6)
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(20)
                        .frame(maxWidth: .infinity)
                        .settingsSurface(14)
                        .padding(.vertical, 16)
                    } else if filtered.isEmpty {
                        Text(L(searchQuery.isEmpty ? "faq_empty" : "faq_empty_search")).font(.dorr(13.5)).foregroundStyle(wa.settingsMut)
                            .frame(maxWidth: .infinity, minHeight: 140)
                    } else {
                        ScrollView {
                            VStack(spacing: 8) {
                                ForEach(filtered) { faq in
                                    FaqRow(item: faq, isOpen: openIds.contains(faq.id)) {
                                        withAnimation(.easeInOut(duration: 0.25)) {
                                            if openIds.contains(faq.id) { openIds.remove(faq.id) } else { openIds.insert(faq.id) }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            .padding(.horizontal, 14)
            .padding(.top, 18)
            .padding(.bottom, 24 + safeBottom)
        }
        .task { await load() }
    }

    private func load() async {
        isLoading = true
        errorMessage = nil
        do {
            faqs = try await ContentApi.faqs().data ?? []
        } catch {
            errorMessage = error.localizedDescription
        }
        isLoading = false
    }
}

private struct FaqRow: View {
    @Environment(\.wa) private var wa
    let item: FaqDto
    let isOpen: Bool
    let onToggle: () -> Void

    var body: some View {
        Button(action: onToggle) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "questionmark.circle.fill")
                    Text(verbatim: item.question).font(.dorr(13.5, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                        .multilineTextAlignment(.leading).lineSpacing(3)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 14, weight: .bold))
                        .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9))
                        .rotationEffect(.degrees(isOpen ? 180 : 0))
                }
                if isOpen {
                    Text(verbatim: item.answer).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(5)
                        .multilineTextAlignment(.leading)
                        .padding(.top, 10)
                        .padding(.leading, 44)
                        .transition(.opacity)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(14)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Notification settings

/// Four switches. On Android they live only while the page is open (nothing is saved yet); the same here.
struct NotificationSettingsScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    @State private var states = [true, true, true, false]

    private let toggles: [(title: String, desc: String, icon: String)] = [
        ("notif_push_title", "notif_push_desc", "bell.fill"),
        ("notif_updates_title", "notif_updates_desc", "doc.plaintext.fill"),
        ("notif_promo_title", "notif_promo_desc", "tag.fill"),
        ("notif_email_title", "notif_email_desc", "envelope.fill"),
    ]

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("account_notifications"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        ForEach(toggles.indices, id: \.self) { index in
                            HStack(spacing: 10) {
                                PinkIcon(icon: toggles[index].icon)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(L(toggles[index].title)).font(.dorr(13.5, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                                    Text(L(toggles[index].desc)).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                                }
                                Spacer(minLength: 8)
                                RedToggle(on: states[index]) { states[index].toggle() }
                            }
                            .padding(.horizontal, 4)
                            .padding(.vertical, 12)
                            if index != toggles.count - 1 {
                                (wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1)
                            }
                        }
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .settingsSurface(18, elevation: 8)
                    .padding(.horizontal, 14)
                    .padding(.top, 6)
                    .padding(.bottom, 16)
                }
            }
        }
    }
}

// MARK: - Invite friends

@MainActor
struct InviteFriendsScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    @State private var code = ""
    @State private var loading = true
    @State private var loadError = false
    @State private var reloadKey = 0

    var body: some View {
        // The store link carries the code (Play's referrer). The app has no App Store id yet, so the same link is shared.
        let storeUrl = "https://play.google.com/store/apps/details?id=com.dorr.app" + (code.isEmpty ? "" : "&referrer=\(code)")
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("invite_title"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        InviteHero()
                        Text(L("invite_headline")).font(.dorr(22, .heavy)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                            .multilineTextAlignment(.center).lineSpacing(4).padding(.horizontal, 8)
                        VStack(spacing: 0) {
                            InviteStep(icon: "square.and.arrow.up.fill", title: L("invite_step1_title"), text: L("invite_step1_body"))
                            InviteStep(icon: "person.badge.plus", title: L("invite_step2_title"), text: L("invite_step2_body"))
                            InviteStep(icon: "gift.fill", title: L("invite_step3_title"), text: L("invite_step3_body"))
                        }
                        .padding(.top, 16)
                        Group {
                            if loading {
                                ProgressView().tint(wa.red)
                            } else if loadError || code.isEmpty {
                                VStack(spacing: 10) {
                                    Text(L("invite_code_failed")).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(.horizontal, 12)
                                    SettingsPrimaryButton(text: L("invite_code_retry")) { reloadKey += 1 }
                                }
                            } else {
                                InviteCodeButton(code: code) {
                                    copyToClipboard(code)
                                    AppToast.show(L("invite_copied"))
                                }
                            }
                        }
                        .padding(.top, 14)
                        SettingsPrimaryButton(text: L("invite_share_now"), enabled: !code.isEmpty) {
                            shareText(L("invite_share_body", code, storeUrl))
                        }
                        .padding(.top, 14)
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
            }
        }
        .task(id: reloadKey) {
            loading = true
            loadError = false
            do {
                code = try await ReferralApi.myCode().data?.code ?? ""
                loadError = code.isEmpty
            } catch {
                loadError = true
            }
            loading = false
        }
    }
}

private struct InviteCodeButton: View {
    @Environment(\.wa) private var wa
    let code: String
    let onCopy: () -> Void
    @State private var copied = false

    var body: some View {
        Button {
            onCopy()
            copied = true
            Task {
                try? await Task.sleep(nanoseconds: 1_600_000_000)
                copied = false
            }
        } label: {
            HStack(spacing: 12) {
                PinkIcon(icon: "square.and.arrow.up.fill", box: 40)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("invite_code_label")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: code).font(.dorr(18, .heavy)).kerning(0.4).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).lineLimit(1)
                        .environment(\.layoutDirection, .leftToRight)
                }
                Spacer(minLength: 8)
                Image(systemName: copied ? "checkmark" : "doc.on.doc").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 40, height: 40)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: Circle())
                    .accessibilityLabel(L("invite_copy"))
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .settingsSurface(18)
        }
        .buttonStyle(.plain)
    }
}

/// Two phones and a heart above them.
private struct InviteHero: View {
    @Environment(\.wa) private var wa

    var body: some View {
        ZStack {
            PhoneBadge(tint: wa.red.opacity(0.72)).offset(x: -22, y: 6)
            PhoneBadge(tint: wa.red).offset(x: 22, y: 6)
            Image(systemName: "heart.fill").font(.system(size: 13)).foregroundStyle(wa.red)
                .frame(width: 32, height: 32)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.16), in: Circle())
                .offset(y: -22)
        }
        .frame(maxWidth: .infinity)
        .frame(height: 118)
    }
}

private struct PhoneBadge: View {
    let tint: Color

    var body: some View {
        Image(systemName: "iphone").font(.system(size: 24, weight: .semibold)).foregroundStyle(.white)
            .frame(width: 56, height: 76)
            .background(tint, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(0.28), lineWidth: 2))
            .shadow(color: tint.opacity(0.22), radius: 6, y: 2)
    }
}

private struct InviteStep: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            PinkIcon(icon: icon, box: 36)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                Text(verbatim: text).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 10)
    }
}

// MARK: - Rate the app

@MainActor
struct RateAppScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    /// Quarter-star steps: 1, 1.25, 1.5 … 5 (0 = not chosen yet).
    @State private var stars: Double = 0
    @State private var comment = ""
    @State private var alreadyRated = false
    @State private var offeredStoreReview = false
    @State private var sending = false

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("rate_title"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        Text(L("rate_headline")).font(.dorr(22, .heavy)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                            .multilineTextAlignment(.center).lineSpacing(4).padding(.top, 8).padding(.bottom, 6)
                        Text(L("rate_body")).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(4)
                            .padding(.horizontal, 8).padding(.vertical, 4)
                        StarPicker(value: stars, activeColor: wa.red, idleColor: wa.settingsMut.opacity(0.45)) { stars = $0 }
                            .padding(.top, 18)
                        Text(verbatim: starsLabel).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 10).padding(.bottom, 18)
                        if alreadyRated {
                            Text(L("rate_already")).font(.dorr(14, .semibold)).foregroundStyle(wa.red).multilineTextAlignment(.center).padding(.bottom, 10)
                        }
                        DorrTextArea(value: Binding(get: { comment }, set: { if $0.count <= 300 { comment = $0 } }),
                                     label: L("rate_comment_label"), placeholder: L("rate_comment_hint"), icon: "text.alignleft", minHeight: 120)
                        Text(verbatim: "\(comment.trimmingCharacters(in: .whitespacesAndNewlines).count) / 300")
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(.top, 6).padding(.bottom, 4)
                        SettingsPrimaryButton(text: L(alreadyRated ? "rate_update" : "rate_submit"), loading: sending, action: submit)
                            .padding(.top, 14)
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .task {
            guard let mine = try? await RatingApi.mine().data, mine.rated, let rating = mine.rating else { return }
            stars = rating.stars
            comment = rating.comment ?? ""
            alreadyRated = true
            if rating.stars >= 4 { offeredStoreReview = true }
        }
    }

    private var starsLabel: String {
        let label: String
        switch stars {
        case ...0: label = L("rate_hint")
        case ..<1.5: label = L("rate_label_1")
        case ..<2.5: label = L("rate_label_2")
        case ..<3.5: label = L("rate_label_3")
        case ..<4.5: label = L("rate_label_4")
        default: label = L("rate_label_5")
        }
        guard stars > 0 else { return label }
        let number = stars.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(stars)) : String(stars).replacingOccurrences(of: #"0+$"#, with: "", options: .regularExpression)
        return "\(label)  ·  \(number)"
    }

    private func submit() {
        guard !sending else { return }
        guard stars >= 1 else {
            AppToast.show(L("rate_pick_stars"))
            return
        }
        let trimmed = comment.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty && !(5...300).contains(trimmed.count) {
            AppToast.show(L("rate_comment_length"))
            return
        }
        UIApplication.shared.sendAction(#selector(UIResponder.resignFirstResponder), to: nil, from: nil, for: nil)
        sending = true
        let wasRated = alreadyRated
        Task {
            do {
                let saved = try await RatingApi.submit(SubmitRatingRequest(stars: stars, comment: trimmed)).data
                alreadyRated = true
                comment = saved?.comment ?? ""
                AppToast.show(L(wasRated ? "rate_updated" : "rate_thanks"))
                // The store's review prompt only the first time this rating becomes 4–5 stars.
                if saved?.promptStoreReview == true && !offeredStoreReview {
                    offeredStoreReview = true
                    if let scene = UIApplication.shared.connectedScenes.first(where: { $0.activationState == .foregroundActive }) as? UIWindowScene {
                        SKStoreReviewController.requestReview(in: scene)
                    }
                }
            } catch {
                AppToast.show(error.apiFailure.message ?? L("rate_failed"))
            }
            sending = false
        }
    }
}

/// Five stars, tapped or dragged to any quarter (1, 1.25 … 5), each drawn partly filled; the fill follows
/// the reading direction (right to left in Arabic).
private struct StarPicker: View {
    @Environment(\.layoutDirection) private var direction
    let value: Double
    let activeColor: Color
    let idleColor: Color
    let onChange: (Double) -> Void

    private let star: CGFloat = 40
    private let gap: CGFloat = 6

    var body: some View {
        let total = star * 5 + gap * 4
        HStack(spacing: gap) {
            ForEach(1...5, id: \.self) { n in
                let fill = CGFloat(min(max(value - Double(n - 1), 0), 1))
                ZStack {
                    Image(systemName: "star").resizable().scaledToFit().foregroundStyle(idleColor)
                    Image(systemName: "star.fill").resizable().scaledToFit().foregroundStyle(activeColor)
                        .mask(alignment: .leading) { Rectangle().frame(width: star * fill) }
                }
                .frame(width: star, height: star)
            }
        }
        .frame(width: total)
        .contentShape(Rectangle())
        .gesture(DragGesture(minimumDistance: 0).onChanged { onChange(valueAt($0.location.x, total: total)) })
    }

    private func valueAt(_ x: CGFloat, total: CGFloat) -> Double {
        // In RTL SwiftUI already reports x from the leading (right) edge, so no flip is needed here.
        let pos = min(max(x, 0), total)
        let stride = star + gap
        let index = min(Int(pos / stride), 4)
        let within = min(max((pos - CGFloat(index) * stride) / star, 0), 1)
        return min(max(Double(index) + ceil(within * 4) / 4, 1), 5)
    }
}

/// androidApp `components/DorrTextField` — label above; a pill on one line, or a rounded 20 box that grows
/// with the text (`singleLine = false`).
struct DorrTextArea: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    var label: String?
    var placeholder: String?
    var icon: String?
    var minHeight: CGFloat = 120
    var error = false
    var singleLine = false
    var enabled = true
    var onSubmit: (() -> Void)?
    /// A one-line field's height (a compact search can be lower).
    var singleLineHeight: CGFloat = 46
    @FocusState private var focused: Bool

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: singleLine ? 999 : 20, style: .continuous)
        VStack(alignment: .leading, spacing: 0) {
            if let label { Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8) }
            HStack(alignment: singleLine ? .center : .top, spacing: 8) {
                if let icon {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(error ? wa.danger : (focused ? wa.red : wa.red.opacity(0.8)))
                        .padding(.top, singleLine ? 0 : 2)
                }
                ZStack(alignment: .topLeading) {
                    if value.isEmpty, let placeholder {
                        Text(verbatim: placeholder).font(.dorr(15)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0xB4BAC4)).lineLimit(1)
                    }
                    Group {
                        if singleLine {
                            TextField("", text: $value)
                        } else {
                            TextField("", text: $value, axis: .vertical)
                        }
                    }
                    .font(.dorr(15))
                    .foregroundStyle(wa.settingsInk)
                    .tint(wa.red)
                    .focused($focused)
                    .disabled(!enabled)
                    .submitLabel(onSubmit == nil ? .return : .send)
                    .onSubmit { onSubmit?() }
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, singleLine ? 0 : 12)
            .frame(maxWidth: .infinity, minHeight: singleLine ? singleLineHeight : minHeight, alignment: singleLine ? .leading : .topLeading)
            .background(wa.night ? wa.bg : wa.surface, in: shape)
            .shadow(color: wa.night ? .clear : .black.opacity(0.08), radius: 3, y: 1)
            .overlay(shape.stroke(error ? wa.danger : (focused ? wa.red : (wa.night ? wa.line : Color.clear)), lineWidth: focused || error ? 1.5 : 1))
            .contentShape(Rectangle())
            .onTapGesture { if enabled { focused = true } }
        }
    }
}
