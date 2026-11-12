import SwiftUI

/// androidApp `LoginScreen.kt` — phone + country, terms, OTP request, restore of a deleted account.
@MainActor
struct LoginScreen: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.wa) private var wa

    @State private var phone = ""
    @State private var showPrivacy = false
    @State private var showTerms = false
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var countries: [CountryDto] = []
    @State private var menuExpanded = false
    /// The server said this phone belongs to a deleted (restorable) account — show the Restore card.

    // Seeded default country is Saudi Arabia until the dropdown answers.
    @State private var selectedCountryId: Int?
    @State private var flagCode = "sa"
    @State private var dialCode = "+966"
    @State private var phoneLength = 9
    @State private var phoneStartsWith = ""

    private var isPhoneValid: Bool { phone.count == phoneLength && (phoneStartsWith.isEmpty || phone.hasPrefix(phoneStartsWith)) }
    /// No checkbox: «By continuing, you agree to …» sits under the button (agreeing by continuing).
    private var isFormValid: Bool { isPhoneValid }
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

            if showPrivacy {
                LegalContentScreen(kind: .privacy) { showPrivacy = false }.transition(.opacity).zIndex(2)
            }
            if showTerms {
                LegalContentScreen(kind: .terms) { showTerms = false }.transition(.opacity).zIndex(2)
            }
        }
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.4), value: router.sessionExpiredNotice || router.accountDeletedNotice)
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
        let mut = AuthColors.mut(wa)
        return ScrollView {
            VStack(spacing: 0) {
                HStack {
                    Spacer()
                    LanguagePicker()
                }
                .frame(maxWidth: 440)

                VStack(spacing: 0) {
                    // Over the phone field: the full Dorr logo (the mark over the name — the dark-letters one by day,
                    // the white-letters one by night), «Welcome to Dorr» and what happens next.
                    Image(wa.night ? "DorrLogoFullDark" : "DorrLogoFullLight").resizable().scaledToFit()
                        .frame(width: 96)
                        .padding(.top, 4)
                        .accessibilityLabel(L("app_name"))
                    Text(L("login_title")).font(.dorr(22, .heavy)).foregroundStyle(accent).multilineTextAlignment(.center)
                        .padding(.top, 18)
                    Text(L("login_subtitle")).font(.dorr(14)).foregroundStyle(mut).multilineTextAlignment(.center).lineSpacing(5)
                        .frame(maxWidth: 320).padding(.top, 4)
                        .padding(.bottom, 20)

                    phoneField

                    submitButton(accent: accent).padding(.top, 20)

                    termsLine(accent: accent, mut: mut).padding(.top, 14)

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
        // A server message is in the language it was asked in: switching language drops it.
        .onChange(of: router.locale) { _ in errorMessage = nil }
    }

    private var phoneField: some View {
        LoginPhoneField(
            countries: countries, selectedCountryId: selectedCountryId, flagCode: flagCode, dialCode: dialCode,
            menuExpanded: $menuExpanded, onCountrySelected: { apply($0); menuExpanded = false },
            phoneStartsWith: phoneStartsWith, phoneLength: phoneLength,
            phone: Binding(get: { phone }, set: { value in
                if value.count <= phoneLength {
                    phone = value
                    errorMessage = nil
                }
            }),
            // The field's own check first, else what the server said («wait 60 s…») — both under the field.
            canSubmit: canSubmit, onSubmit: submit, error: phoneError ?? errorMessage
        )
    }

    /// Under the button: «By continuing, you agree to the Terms of Use and the Privacy Policy», centred — each link in
    /// the app's colour, underlined, opening its page over the login page. Continuing is the agreement (no checkbox).
    private func termsLine(accent: Color, mut: Color) -> some View {
        Text(termsText(accent: accent, mut: mut))
            .font(.dorr(12))
            .lineSpacing(4)
            .multilineTextAlignment(.center)
            .frame(maxWidth: .infinity)
            // Links take the tint, not their foreground colour.
            .tint(accent)
            .environment(\.openURL, OpenURLAction { url in
                if url.host == "terms" { showTerms = true } else { showPrivacy = true }
                return .handled
            })
    }

    /// "By continuing, you agree to the Terms of Use and Privacy Policy" with the two names as real links (underlined,
    /// semibold).
    private func termsText(accent: Color, mut: Color) -> AttributedString {
        func part(_ text: String, link: String? = nil) -> AttributedString {
            var piece = AttributedString(text)
            piece.foregroundColor = link == nil ? mut : accent
            if let link {
                piece.link = URL(string: "dorr://\(link)")
                piece.underlineStyle = .single
                piece.font = .dorr(12, .semibold)
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
                    Text(L("login_cta")).font(.dorr(16, .bold))
                }
            }
            // Filled in the app's colour once the number is right; before that a soft grey fill with grey words (no
            // outline) — clearly not ready yet (androidApp's disabled colours).
            .foregroundStyle(canSubmit || isLoading ? .white : AuthColors.soft(wa))
            .frame(maxWidth: .infinity, minHeight: 52)
            .background(canSubmit || isLoading ? accent : loginSoftFill(wa, 0.6), in: Capsule())
            .shadow(color: isFormValid && !isLoading ? accent.opacity(0.4) : .clear, radius: 8, y: 4)
        }
        .buttonStyle(.plain)
        // Its disabled look is drawn above (grey fill); SwiftUI's own dimming would add to it.
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
        Task {
            do {
                let dto = try await MobileAuthApi.requestOtp(OtpRequest(dialCode: dialCode, phone: phone)).data
                if dto?.accountState == "deleted" {
                    // The account was deleted: send the restore code straight away and open the restore page (it says
                    // «Restore your account» itself) — no card to tap first. Back returns here.
                    router.accountDeletedNotice = false
                    _ = try await MobileAuthApi.requestRestoreOtp(OtpRequest(dialCode: dialCode, phone: phone))
                    isLoading = false
                    router.otpRequested(dialCode: dialCode, phone: phone, restore: true)
                } else {
                    isLoading = false
                    router.otpRequested(dialCode: dialCode, phone: phone, restore: false)
                }
            } catch {
                isLoading = false
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
        // Signed out / account deleted: the danger red (the admin's), on the app's card.
        let bad = AuthColors.danger(wa)
        let titleColor = AuthColors.onStatus(bad, night: wa.night)
        let messageColor = AuthColors.mut(wa)
        HStack(spacing: 0) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(titleColor)
                .frame(width: 40, height: 40)
                .background(bad.opacity(wa.night ? 0.22 : 0.14), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.dorr(13.5, .bold)).foregroundStyle(titleColor)
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
        .background(AuthColors.card(wa).mix(bad, wa.night ? 0.16 : 0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(AuthColors.statusFrame(bad), lineWidth: 1))
        .shadow(color: Color(hex: 0x001B53).opacity(0.2), radius: 10, y: 4)
    }
}

// MARK: - Phone field

/// androidApp `PhoneField` — «Phone number» over the field; the field: the country on a soft background (flag, dial
/// code, caret — opens the searchable country list) | the number, grouped as it's typed («10 1234 5678»), clear ×;
/// under it what a number here looks like («10 digits starting with 1») or, in red, what's wrong with it. The frame
/// takes the app's colour while typing and red with an error. Always laid out left-to-right, like a phone number.
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
    let error: String?
    @FocusState private var focused: Bool

    var body: some View {
        let ink = AuthColors.ink(wa)
        let mut = AuthColors.mut(wa)
        let danger = AuthColors.danger(wa)
        let line = AuthColors.line(wa)
        let groups = phoneGroups(phoneLength)
        // «1X XXXX XXXX»: the digits a number starts with, then X for the rest, grouped like the typed number.
        let placeholder = groupPhone(phoneStartsWith + String(repeating: "X", count: max(phoneLength - phoneStartsWith.count, 0)), groups)
        let border = error != nil ? danger : (focused ? wa.red : line)
        let hint: String = phoneLength <= 0 ? "" :
            Lp("login_phone_digits", phoneLength, String(phoneLength)) + (phoneStartsWith.isEmpty ? "" : " " + L("login_phone_starts", phoneStartsWith))
        VStack(alignment: .leading, spacing: 0) {
            Text(L("login_phone_label")).font(.dorr(13.5, .bold)).foregroundStyle(ink)
                .padding(.leading, 2).padding(.bottom, 8)
            HStack(spacing: 0) {
                // The country: its flag, its code and a caret, on a soft background — it opens the list.
                Button { if !countries.isEmpty { menuExpanded = true } } label: {
                    HStack(spacing: 6) {
                        FlagImage(code: flagCode)
                        Text(verbatim: dialCode).font(.dorr(15, .bold)).foregroundStyle(ink)
                        if !countries.isEmpty {
                            Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold))
                                .foregroundStyle(mut).padding(.leading, 2)
                        }
                    }
                    .padding(.leading, 12).padding(.trailing, 8)
                    .frame(maxHeight: .infinity)
                    .background(loginSoftFill(wa, 0.4))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("login_country_code"))
                .popover(isPresented: $menuExpanded) {
                    CountryMenu(countries: countries, selectedCountryId: selectedCountryId, onSelect: onCountrySelected)
                        .compactPopover()
                }
                line.frame(width: 1).frame(maxHeight: .infinity)
                ZStack(alignment: .leading) {
                    if phone.isEmpty {
                        Text(verbatim: placeholder).font(.dorr(16, .semibold)).foregroundStyle(AuthColors.soft(wa))
                    }
                    // Shown in groups («10 1234 5678»); the number itself stays plain digits (sent as is).
                    TextField("", text: Binding(get: { groupPhone(phone, groups) }, set: { phone = String(asciiDigits($0).prefix(phoneLength)) }))
                        .font(.dorr(16, .semibold))
                        .foregroundStyle(ink)
                        .tint(wa.red)
                        .keyboardType(.phonePad)
                        .textContentType(.telephoneNumber)
                        .focused($focused)
                        .submitLabel(.done)
                        .onSubmit { if canSubmit { onSubmit() } }
                }
                .padding(.leading, 14)
                if !phone.isEmpty {
                    Button { phone = "" } label: {
                        Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(AuthColors.soft(wa))
                            .frame(width: 28, height: 28)
                    }
                    .buttonStyle(.plain)
                }
                Spacer().frame(width: 8)
            }
            .frame(height: 52)
            .background(AuthColors.card(wa))
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(border, lineWidth: (focused || error != nil) ? 1.5 : 1))
            .environment(\.layoutDirection, .leftToRight)
            // Under the field: what's wrong (the field's check or the server's answer) as a red line with its icon, like
            // the OTP page — else what a number here looks like.
            if let error {
                HStack(spacing: 5) {
                    Image(systemName: "exclamationmark.circle").font(.system(size: 13, weight: .semibold))
                    Text(verbatim: error).font(.dorr(12.5, .medium))
                }
                .foregroundStyle(AuthColors.onStatus(danger, night: wa.night))
                .padding(.leading, 2).padding(.top, 6)
            } else if !hint.isEmpty {
                Text(verbatim: hint).font(.dorr(12)).foregroundStyle(mut)
                    .padding(.leading, 2).padding(.top, 6)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// androidApp `loginSoftFill` — the sign-in's soft fill: the country part of the phone field (0.4) and «Send» before
/// the number is right (0.6) — the app's card colour mixed with its frame colour, so it follows the admin's colours
/// by day and by night (was a fixed grey by day and a fixed brown, `darkWell`, by night).
@MainActor
func loginSoftFill(_ wa: WaPalette, _ amount: Double) -> Color {
    wa.surface.mix(wa.line, amount)
}

/// androidApp `phoneGroups`: how a number of [length] digits is grouped — two digits, then fours (the first of those
/// shorter if need be).
func phoneGroups(_ length: Int) -> [Int] {
    if length <= 4 { return [max(length, 1)] }
    var tail: [Int] = []
    var rest = length - 2
    while rest > 0 { tail.insert(min(rest, 4), at: 0); rest -= 4 }
    return [2] + tail
}

/// androidApp `groupPhone`: [digits] with a space between the [groups] («1012345678» → «10 1234 5678»).
func groupPhone(_ digits: String, _ groups: [Int]) -> String {
    let chars = Array(digits)
    var parts: [String] = []
    var i = 0
    for size in groups where i < chars.count {
        parts.append(String(chars[i..<min(i + size, chars.count)]))
        i += size
    }
    if i < chars.count { parts.append(String(chars[i...])) }
    return parts.joined(separator: " ")
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
                Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(AuthColors.soft(wa))
                ZStack(alignment: .leading) {
                    if query.isEmpty { Text(L("login_country_search")).font(.dorr(14)).foregroundStyle(AuthColors.soft(wa)) }
                    TextField("", text: $query).font(.dorr(14)).foregroundStyle(AuthColors.ink(wa)).autocorrectionDisabled()
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 8)
            // The search box on the sign-in's soft fill.
            .background(loginSoftFill(wa, 0.5), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
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
                                    .foregroundStyle(tint ?? AuthColors.ink(wa))
                                    .lineLimit(1)
                                    .padding(.leading, 10)
                                Spacer(minLength: 8)
                                Text(verbatim: formatDialCode(country.dialCode)).font(.dorr(13, .semibold))
                                    .foregroundStyle(tint ?? AuthColors.mut(wa))
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
        .background(AuthColors.card(wa))
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

/// androidApp `LanguagePicker` — the language button. With two languages (Arabic and English, or the list not loaded)
/// it shows the *other* one in its own name — «English» while the app is in Arabic — and one tap switches straight to
/// it. With three or more it shows the current one with a caret and opens the list (the chosen one with a check); a
/// downloadable language is fetched first and applied only if that worked.
@MainActor
struct LanguagePicker: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.wa) private var wa
    @State private var languages: [LanguageDto] = []
    @State private var expanded = false
    @State private var selected: LanguageDto?
    @State private var downloadFailed = false

    /// Two languages (or none listed yet — the app ships Arabic and English): a one-tap switch to the other.
    private var toggle: Bool { languages.count <= 2 }
    private var otherCode: String {
        languages.first { $0.code.caseInsensitiveCompare(router.locale) != .orderedSame }?.code
            ?? (router.locale.lowercased() == "ar" ? "en" : "ar")
    }
    /// A language's own name ("English", "العربية"); others keep the server's name.
    private var otherName: String {
        switch otherCode.lowercased() {
        case "ar": return "العربية"
        case "en": return "English"
        default: return languages.first { $0.code.caseInsensitiveCompare(otherCode) == .orderedSame }?.name ?? otherCode.uppercased()
        }
    }

    var body: some View {
        Button {
            if toggle {
                let next = otherCode
                Task { if !(await router.chooseLanguage(next)) { downloadFailed = true } }
            } else if !languages.isEmpty {
                expanded = true
            }
        } label: {
            HStack(spacing: 8) {
                Image(systemName: "globe").font(.system(size: 16, weight: .medium)).foregroundStyle(wa.red)
                Text(verbatim: toggle ? otherName : (selected?.name ?? L("language_arabic"))).font(.dorr(13, .bold))
                    .foregroundStyle(wa.night ? wa.ink : wa.red)
                if !toggle { Triangle().fill(wa.red).frame(width: 8, height: 5) }
            }
            .padding(.horizontal, 12)
            .frame(height: 34)
            .contentShape(Capsule())
            .overlay(Capsule().stroke(AuthColors.line(wa), lineWidth: 1))
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
                                .foregroundStyle(isSelected ? wa.accent : AuthColors.ink(wa))
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
            .background(AuthColors.card(wa))
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
