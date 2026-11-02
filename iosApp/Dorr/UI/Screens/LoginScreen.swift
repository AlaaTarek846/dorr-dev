import SwiftUI

/// androidApp `LoginScreen.kt` — phone + country, terms, OTP request, restore of a deleted account.
@MainActor
struct LoginScreen: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.wa) private var wa

    @State private var phone = ""
    @State private var acceptedTerms = false
    @State private var showPrivacy = false
    @State private var showTerms = false
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var countries: [CountryDto] = []
    @State private var menuExpanded = false
    /// The server said this phone belongs to a deleted (restorable) account — show the Restore card.
    @State private var showRestoreCard = false
    @State private var restoreRequesting = false

    // Seeded default country is Saudi Arabia until the dropdown answers.
    @State private var selectedCountryId: Int?
    @State private var flagCode = "sa"
    @State private var dialCode = "+966"
    @State private var phoneLength = 9
    @State private var phoneStartsWith = ""

    private var isPhoneValid: Bool { phone.count == phoneLength && (phoneStartsWith.isEmpty || phone.hasPrefix(phoneStartsWith)) }
    private var isFormValid: Bool { acceptedTerms && isPhoneValid }
    private var canSubmit: Bool { !isLoading && isFormValid }

    private var phoneError: String? {
        if phone.isEmpty { return nil }
        if phone.count != phoneLength { return L("login_error_phone_invalid_length", phoneLength) }
        if !phoneStartsWith.isEmpty && !phone.hasPrefix(phoneStartsWith) { return L("login_error_phone_invalid_start", phoneStartsWith) }
        return nil
    }

    var body: some View {
        ZStack(alignment: .top) {
            PinkBackdrop()
            content

            // Session expired / account deleted banner, sliding in from the top.
            if router.sessionExpiredNotice || router.accountDeletedNotice {
                Group {
                    if router.sessionExpiredNotice {
                        NoticeBanner(title: L("session_expired_title"), message: L("session_expired_message"), icon: "lock.fill") {
                            router.sessionExpiredNotice = false
                        }
                    } else {
                        NoticeBanner(title: L("account_deleted_title"), message: L("account_deleted_message"), icon: "trash.fill") {
                            router.accountDeletedNotice = false
                        }
                    }
                }
                .frame(maxWidth: 440)
                .padding(.horizontal, 16)
                .padding(.top, 16)
                .transition(.move(edge: .top).combined(with: .opacity))
            }

            // A deleted (but restorable) account — Restore jumps into the OTP flow to bring it back.
            if showRestoreCard {
                RestoreAccountCard(restoring: restoreRequesting, onRestore: restoreAccount, onDismiss: { showRestoreCard = false })
                    .frame(maxWidth: 440)
                    .padding(.horizontal, 16)
                    .padding(.top, 16)
                    .transition(.move(edge: .top).combined(with: .opacity))
            }

            if showPrivacy {
                LegalContentScreen(kind: .privacy) { showPrivacy = false }.transition(.opacity).zIndex(2)
            }
            if showTerms {
                LegalContentScreen(kind: .terms) { showTerms = false }.transition(.opacity).zIndex(2)
            }
        }
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.4), value: router.sessionExpiredNotice || router.accountDeletedNotice)
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.35), value: showRestoreCard)
        .task(id: router.sessionExpiredNotice) {
            guard router.sessionExpiredNotice else { return }
            try? await Task.sleep(nanoseconds: 5_500_000_000)
            if !Task.isCancelled { router.sessionExpiredNotice = false }
        }
        .task(id: router.accountDeletedNotice) {
            guard router.accountDeletedNotice else { return }
            try? await Task.sleep(nanoseconds: 7_000_000_000)
            if !Task.isCancelled { router.accountDeletedNotice = false }
        }
        .task { await loadCountries() }
    }

    private var content: some View {
        let accent = wa.accent
        let mut = wa.night ? wa.mut : AppearanceState.shared.color("authTextMuted", Color(hex: 0x6B7280), dark: false)
        return ScrollView {
            VStack(spacing: 0) {
                HStack {
                    Spacer()
                    LanguagePicker()
                }
                .frame(maxWidth: 440)

                VStack(spacing: 0) {
                    Text(L("login_title")).font(.dorr(30, .heavy)).foregroundStyle(accent).multilineTextAlignment(.center).padding(.top, 8)
                    Text(L("login_subtitle")).font(.dorr(14)).foregroundStyle(mut).multilineTextAlignment(.center).lineSpacing(5)
                        .frame(maxWidth: 260).padding(.top, 8)
                    DorrLogo(width: 156, onDark: wa.night).padding(.top, 22).padding(.bottom, 26)

                    phoneField

                    if let phoneError {
                        let errorInk = wa.night ? Color(hex: 0xFFB4B4) : AppColors.danger
                        HStack(spacing: 8) {
                            Image(systemName: "exclamationmark.circle").font(.system(size: 14, weight: .semibold))
                            Text(phoneError).font(.dorr(12, .medium))
                            Spacer(minLength: 0)
                        }
                        .foregroundStyle(errorInk)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .background(AppColors.danger.opacity(wa.night ? 0.16 : 0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 10, style: .continuous).stroke(AppColors.danger.opacity(wa.night ? 0.38 : 0.28), lineWidth: 1))
                        .padding(.top, 8)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    }

                    termsRow(accent: accent, mut: mut).padding(.top, 12)

                    submitButton(accent: accent).padding(.top, 16)

                    if let errorMessage {
                        HStack(spacing: 10) {
                            Image(systemName: "exclamationmark.circle").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                            Text(errorMessage).font(.dorr(12, .medium)).foregroundStyle(wa.night ? wa.darkAccent : Color(hex: 0x001B53))
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.darkAccent : Color(hex: 0xFBC8B7), lineWidth: 1))
                        .padding(.top, 16)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    }
                }
                .frame(maxWidth: 440)
                .animation(.easeOut(duration: 0.2), value: phoneError)
                .animation(.easeOut(duration: 0.2), value: errorMessage)
            }
            .padding(.horizontal, 24)
            .padding(.top, 12)
            .padding(.bottom, 28)
            .frame(maxWidth: .infinity)
        }
        .scrollDismissesKeyboard(.interactively)
    }

    private var phoneField: some View {
        LoginPhoneField(
            countries: countries, selectedCountryId: selectedCountryId, flagCode: flagCode, dialCode: dialCode,
            menuExpanded: $menuExpanded, onCountrySelected: { apply($0); menuExpanded = false },
            phoneStartsWith: phoneStartsWith, phoneLength: phoneLength,
            phone: Binding(get: { phone }, set: { value in
                if value.count <= phoneLength {
                    phone = value
                    if showRestoreCard { showRestoreCard = false }
                }
            }),
            canSubmit: canSubmit, onSubmit: submit
        )
    }

    private func termsRow(accent: Color, mut: Color) -> some View {
        HStack(alignment: .center, spacing: 8) {
            Button { acceptedTerms.toggle() } label: {
                Image(systemName: acceptedTerms ? "checkmark.square.fill" : "square")
                    .font(.system(size: 21))
                    .foregroundStyle(acceptedTerms ? accent : (wa.night ? wa.line : Color(hex: 0xD1D5DB)))
                    .frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            // The two links open the terms / the privacy policy over the login page.
            Text(termsText(accent: accent, mut: mut))
                .font(.dorr(13))
                .lineSpacing(4)
                // Links take the tint, not their foreground colour.
                .tint(accent)
                .environment(\.openURL, OpenURLAction { url in
                    if url.host == "terms" { showTerms = true } else { showPrivacy = true }
                    return .handled
                })
            Spacer(minLength: 0)
        }
        .padding(.vertical, 4)
    }

    /// "I agree to the Terms of Use and Privacy Policy" with the two names as real links (underlined, semibold).
    private func termsText(accent: Color, mut: Color) -> AttributedString {
        func part(_ text: String, link: String? = nil) -> AttributedString {
            var piece = AttributedString(text)
            piece.foregroundColor = link == nil ? mut : accent
            if let link {
                piece.link = URL(string: "dorr://\(link)")
                piece.underlineStyle = .single
                piece.font = .dorr(13, .semibold)
            }
            return piece
        }
        return part(L("login_terms_prefix")) + part(L("login_terms_use"), link: "terms") + part(L("login_terms_mid"))
            + part(L("login_terms_privacy"), link: "privacy")
    }

    private func submitButton(accent: Color) -> some View {
        Button(action: submit) {
            ZStack {
                if isLoading {
                    ProgressView().tint(.white)
                } else {
                    Text(L("login_cta")).font(.dorr(15, .bold))
                }
            }
            .foregroundStyle(canSubmit || isLoading ? .white : (wa.night ? accent.opacity(0.92) : accent.opacity(0.55)))
            .frame(maxWidth: .infinity, minHeight: 48)
            .background(canSubmit || isLoading ? accent : .clear, in: Capsule())
            .overlay { if !canSubmit && !isLoading { Capsule().stroke(accent.opacity(wa.night ? 0.55 : 0.35), lineWidth: 1) } }
            .shadow(color: isFormValid && !isLoading ? accent.opacity(0.4) : .clear, radius: 8, y: 4)
        }
        .buttonStyle(.plain)
        // Its disabled look is drawn above (outline only); SwiftUI's own dimming would add to it.
        .allowsHitTesting(canSubmit)
    }

    // MARK: - Actions

    private func apply(_ country: CountryDto) {
        selectedCountryId = country.id
        flagCode = country.flag?.code ?? country.code
        dialCode = formatDialCode(country.dialCode)
        let length = country.phoneLength ?? phoneLength
        phoneLength = length
        phoneStartsWith = country.phoneStartsWith ?? ""
        if phone.count > length { phone = String(phone.prefix(length)) }
    }

    private func loadCountries() async {
        let listed = (try? await CountryApi.list().data) ?? []
        countries = listed
        if !listed.isEmpty { CountryCache.save(listed) }
        let fallback = listed.first(where: \.isDefault) ?? listed.first
        if let fallback { apply(fallback) }
        // The country the phone is in: the server's guess by IP; when that only gives the default, the
        // phone's region (iOS has no SIM country for apps). Either one only if it's in the list.
        let byIp = (try? await CountryApi.detect().data).flatMap { found in listed.first { $0.id == found.id } }
        let byDevice = Locale.current.region.flatMap { region in listed.first { $0.code.caseInsensitiveCompare(region.identifier) == .orderedSame } }
        let chosen = (byIp?.isDefault == false ? byIp : nil) ?? byDevice ?? byIp
        // Don't override a country the user already picked by hand meanwhile.
        if let chosen, selectedCountryId == fallback?.id, phone.isEmpty { apply(chosen) }
    }

    private func submit() {
        guard canSubmit else { return }
        isLoading = true
        errorMessage = nil
        showRestoreCard = false
        Task {
            do {
                let dto = try await MobileAuthApi.requestOtp(OtpRequest(dialCode: dialCode, phone: phone)).data
                isLoading = false
                if dto?.accountState == "deleted" {
                    // The account exists but was deleted — offer the restore step instead of the OTP.
                    router.accountDeletedNotice = false
                    showRestoreCard = true
                } else {
                    router.otpRequested(dialCode: dialCode, phone: phone, restore: false)
                }
            } catch {
                isLoading = false
                errorMessage = error.serverMessage ?? L("login_error_generic")
            }
        }
    }

    /// The user picked "Restore Account": send the restore OTP, then verify via the OTP screen.
    private func restoreAccount() {
        guard !restoreRequesting else { return }
        restoreRequesting = true
        errorMessage = nil
        Task {
            do {
                _ = try await MobileAuthApi.requestRestoreOtp(OtpRequest(dialCode: dialCode, phone: phone))
                restoreRequesting = false
                router.otpRequested(dialCode: dialCode, phone: phone, restore: true)
            } catch {
                restoreRequesting = false
                errorMessage = error.serverMessage ?? L("login_error_generic")
            }
        }
    }
}

func formatDialCode(_ value: String) -> String {
    var digits = value.trimmingCharacters(in: .whitespaces)
    if digits.hasPrefix("+") { digits.removeFirst() }
    return digits.isEmpty ? "" : "+\(digits)"
}

// MARK: - Banners

/// Session expired / account deleted (`NoticeBanner` on Android).
struct NoticeBanner: View {
    @Environment(\.wa) private var wa
    let title: String
    let message: String
    let icon: String
    let onDismiss: () -> Void

    var body: some View {
        let messageColor = wa.night ? Color(hex: 0xE5C0C4) : Color(hex: 0x7F1D1D)
        HStack(spacing: 0) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 40, height: 40)
                .background(wa.night ? Color(hex: 0x4A1E24) : Color(hex: 0xFEE2E2), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.dorr(13.5, .bold)).foregroundStyle(wa.night ? Color(hex: 0xFDE8E8) : Color(hex: 0x001B53))
                Text(message).font(.dorr(12)).foregroundStyle(messageColor).lineSpacing(2)
            }
            .padding(.leading, 12)
            Spacer(minLength: 8)
            Button(action: onDismiss) {
                Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(messageColor.opacity(0.8)).frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("common_close"))
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .background(wa.night ? Color(hex: 0x2B191C) : Color(hex: 0xFFF5F5), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.night ? wa.red.opacity(0.45) : Color(hex: 0xFCA5A5), lineWidth: 1))
        .shadow(color: Color(hex: 0x001B53).opacity(0.2), radius: 10, y: 4)
    }
}

/// The login attempt hit a soft-deleted account — offer to restore it via OTP.
private struct RestoreAccountCard: View {
    @Environment(\.wa) private var wa
    let restoring: Bool
    let onRestore: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        let messageColor = wa.night ? Color(hex: 0xA7C4B2) : Color(hex: 0x166534)
        HStack(spacing: 0) {
            Image(systemName: "clock.arrow.circlepath").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 40, height: 40)
                .background(wa.night ? Color(hex: 0x2F3D45) : Color(hex: 0xDCFCE7), in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(L("account_deleted_title")).font(.dorr(13.5, .bold)).foregroundStyle(wa.night ? Color(hex: 0xDCFCE7) : Color(hex: 0x14532D))
                Text(L("account_restore_message")).font(.dorr(12)).foregroundStyle(messageColor).lineSpacing(2).padding(.top, 2)
                Button(action: onRestore) {
                    HStack(spacing: 6) {
                        if restoring { ProgressView().tint(wa.red).scaleEffect(0.6).frame(width: 14, height: 14) }
                        Text(L("account_restore_action")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 7)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(restoring)
                .padding(.top, 8)
            }
            .padding(.leading, 12)
            Spacer(minLength: 8)
            Button(action: onDismiss) {
                Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(messageColor.opacity(0.8)).frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("common_close"))
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .background(wa.night ? Color(hex: 0x1F2A24) : Color(hex: 0xF0FDF4), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.night ? AppColors.success.opacity(0.45) : Color(hex: 0x86EFAC), lineWidth: 1))
        .shadow(color: Color(hex: 0x001B53).opacity(0.2), radius: 10, y: 4)
    }
}

// MARK: - Phone field

/// The 44pt pill: phone icon | flag + dial code (opens the searchable country list) | the number, clear ×.
/// Always laid out left-to-right, like a phone number is written.
private struct LoginPhoneField: View {
    @Environment(\.wa) private var wa
    let countries: [CountryDto]
    let selectedCountryId: Int?
    let flagCode: String
    let dialCode: String
    @Binding var menuExpanded: Bool
    let onCountrySelected: (CountryDto) -> Void
    let phoneStartsWith: String
    let phoneLength: Int
    @Binding var phone: String
    let canSubmit: Bool
    let onSubmit: () -> Void
    @FocusState private var focused: Bool

    var body: some View {
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        let placeholder = phoneStartsWith + String(repeating: "*", count: max(phoneLength - phoneStartsWith.count, 0))
        HStack(spacing: 0) {
            Image(systemName: "phone.fill").font(.system(size: 15)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x9CA3AF))
            separator
            Button { if !countries.isEmpty { menuExpanded = true } } label: {
                HStack(spacing: 4) {
                    FlagImage(code: flagCode)
                    Text(verbatim: dialCode).font(.dorr(14, .bold)).foregroundStyle(ink)
                    if !countries.isEmpty {
                        Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold))
                            .foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary).padding(.leading, 2)
                    }
                }
                .padding(6)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("login_country_code"))
            .popover(isPresented: $menuExpanded) {
                CountryMenu(countries: countries, selectedCountryId: selectedCountryId, onSelect: onCountrySelected)
                    .compactPopover()
            }
            separator
            ZStack(alignment: .leading) {
                if phone.isEmpty {
                    Text(verbatim: placeholder).font(.dorr(14, .medium)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                }
                TextField("", text: Binding(get: { phone }, set: { phone = String(asciiDigits($0).prefix(phoneLength)) }))
                    .font(.dorr(14, .medium))
                    .foregroundStyle(ink)
                    .tint(wa.red)
                    .keyboardType(.phonePad)
                    .textContentType(.telephoneNumber)
                    .focused($focused)
                    .submitLabel(.done)
                    .onSubmit { if canSubmit { onSubmit() } }
            }
            if !phone.isEmpty {
                Button { phone = "" } label: {
                    Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x9CA3AF))
                        .frame(width: 28, height: 28)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 44)
        .background(wa.night ? wa.darkCard : .white, in: Capsule())
        .overlay { if focused { Capsule().stroke(wa.red, lineWidth: 1.5) } }
        .shadow(color: wa.night ? .clear : .black.opacity(0.1), radius: 3, y: 1.5)
        .environment(\.layoutDirection, .leftToRight)
    }

    private var separator: some View {
        (wa.night ? wa.line : Color(hex: 0xE5E7EB)).frame(width: 1, height: 18).padding(.horizontal, 8)
    }
}

/// The country dropdown: a search box, then every country (flag, name, dial code), the chosen one tinted.
private struct CountryMenu: View {
    @Environment(\.wa) private var wa
    let countries: [CountryDto]
    let selectedCountryId: Int?
    let onSelect: (CountryDto) -> Void
    @State private var query = ""

    var body: some View {
        let q = query.trimmingCharacters(in: .whitespaces)
        let visible = countries.filter { q.isEmpty || $0.name.localizedCaseInsensitiveContains(q) || $0.code.localizedCaseInsensitiveContains(q) || $0.dialCode.contains(q) }
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                ZStack(alignment: .leading) {
                    if query.isEmpty { Text(L("login_country_search")).font(.dorr(14)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted) }
                    TextField("", text: $query).font(.dorr(14)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).autocorrectionDisabled()
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 8)
            .background(wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            .padding(8)
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(visible) { country in
                        let selected = country.id == selectedCountryId
                        let tint = selected ? wa.accent : nil
                        Button { onSelect(country) } label: {
                            HStack(spacing: 0) {
                                FlagImage(code: country.flag?.code ?? country.code)
                                Text(verbatim: country.name.isEmpty ? country.code : country.name)
                                    .font(.dorr(14, selected ? .bold : .regular))
                                    .foregroundStyle(tint ?? (wa.night ? wa.ink : AppColors.textPrimary))
                                    .lineLimit(1)
                                    .padding(.leading, 10)
                                Spacer(minLength: 8)
                                Text(verbatim: formatDialCode(country.dialCode)).font(.dorr(13, .semibold))
                                    .foregroundStyle(tint ?? (wa.night ? wa.mut : AppColors.textSecondary))
                            }
                            .padding(.horizontal, 12)
                            .frame(height: 48)
                            .background(selected ? (wa.night ? wa.darkWell : wa.red.opacity(0.14)) : .clear)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .frame(minWidth: 260, maxHeight: 340)
        .background(wa.night ? wa.darkCard : .white)
    }
}

extension View {
    /// A popover that stays a popover on iPhone (Android's DropdownMenu), where iOS 16.4+ allows it.
    @ViewBuilder
    func compactPopover() -> some View {
        if #available(iOS 16.4, *) {
            presentationCompactAdaptation(.popover)
        } else {
            self
        }
    }
}

// MARK: - Language picker

/// androidApp `LanguagePicker` — the languages the server offers (ar / en and any downloadable one), the
/// chosen one with a check; a downloadable language is fetched first and applied only if that worked.
@MainActor
struct LanguagePicker: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.wa) private var wa
    @State private var languages: [LanguageDto] = []
    @State private var expanded = false
    @State private var selected: LanguageDto?
    @State private var downloadFailed = false

    var body: some View {
        Button { if !languages.isEmpty { expanded = true } } label: {
            HStack(spacing: 8) {
                Image(systemName: "globe").font(.system(size: 17, weight: .medium)).foregroundStyle(wa.red)
                Text(verbatim: selected?.name ?? L("language_arabic")).font(.dorr(13, .semibold))
                    .foregroundStyle(wa.night ? wa.ink : Color(hex: 0x374151))
                Triangle().fill(wa.red).frame(width: 8, height: 5)
            }
            .padding(.horizontal, 12)
            .frame(height: 36)
            .background(wa.night ? wa.darkCard : .white, in: Capsule())
            .shadow(color: wa.night ? .clear : .black.opacity(0.12), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .popover(isPresented: $expanded) {
            VStack(spacing: 0) {
                ForEach(languages) { language in
                    let isSelected = language.code.caseInsensitiveCompare(selected?.code ?? "") == .orderedSame
                    Button { choose(language) } label: {
                        HStack(spacing: 8) {
                            FlagImage(code: languageFlagCode(language))
                            Text(verbatim: language.name).font(.dorr(13, isSelected ? .bold : .medium))
                                .foregroundStyle(isSelected ? wa.accent : (wa.night ? wa.ink : Color(hex: 0x374151)))
                            Spacer(minLength: 12)
                            if isSelected { Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red) }
                        }
                        .padding(.horizontal, 14)
                        .frame(minWidth: 180, minHeight: 46)
                        .background(isSelected ? (wa.night ? wa.darkWell : wa.red.opacity(0.14)) : .clear)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.vertical, 6)
            .background(wa.night ? wa.darkCard : .white)
            .compactPopover()
        }
        .alert(L("language_download_failed"), isPresented: $downloadFailed) { Button("OK", role: .cancel) {} }
        .task(id: router.locale) {
            let listed = (try? await LanguageApi.list().data) ?? []
            languages = listed
            selected = listed.first { $0.code.caseInsensitiveCompare(router.locale) == .orderedSame }
                ?? listed.first { $0.code.caseInsensitiveCompare("ar") == .orderedSame }
                ?? listed.first
        }
    }

    private func choose(_ language: LanguageDto) {
        expanded = false
        Task {
            if await router.chooseLanguage(language.code) { selected = language } else { downloadFailed = true }
        }
    }

    private func languageFlagCode(_ language: LanguageDto) -> String {
        let fromFlag = (language.flag?.code ?? "").trimmingCharacters(in: .whitespaces).lowercased()
        if fromFlag.count == 2 { return fromFlag }
        switch language.code.lowercased() {
        case "ar": return "sa"
        case "en": return "us"
        default: return String(language.code.lowercased().prefix(2))
        }
    }
}

/// The picker's small downward caret.
struct Triangle: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        path.move(to: CGPoint(x: rect.minX, y: rect.minY))
        path.addLine(to: CGPoint(x: rect.maxX, y: rect.minY))
        path.addLine(to: CGPoint(x: rect.midX, y: rect.maxY))
        path.closeSubpath()
        return path
    }
}
