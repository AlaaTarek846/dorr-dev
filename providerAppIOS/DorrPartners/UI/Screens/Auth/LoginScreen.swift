import CoreTelephony
import SwiftUI

/// providerApp `LoginScreen.kt` — sign in with the phone number (docs/moving_tasks.md T1): the country and number, then a code by SMS.
/// There is no separate sign-up — the first code creates the account. The countries are read once when the page opens.
@MainActor
struct LoginScreen: View {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa
    let onOtpSent: (_ dialCode: String, _ phone: String, _ cooldown: Int) -> Void

    @State private var phone = ""
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var countries: [CountryDto] = []
    @State private var menuExpanded = false
    // The terms must be agreed to before a code is asked for; the two links open the policies.
    @State private var acceptedTerms = false
    @State private var showPrivacy = false
    @State private var showTerms = false

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
        let accent = wa.accent
        let mut = wa.night ? wa.mut : appearance.color("authTextMuted", AppColors.textSecondary, dark: false)
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        Spacer()
                        LanguageSwitch()
                    }
                    .padding(.bottom, 8)
                    VStack(spacing: 0) {
                        Text(L("login_title")).font(.dorr(28, .heavy)).foregroundStyle(accent).multilineTextAlignment(.center)
                        Text(L("login_subtitle")).font(.dorr(14)).foregroundStyle(mut).multilineTextAlignment(.center).lineSpacing(6)
                            .frame(maxWidth: 280).padding(.top, 8)
                        DorrLogo(width: 140, onDark: wa.night).padding(.top, 22).padding(.bottom, 26)
                        PhoneField(
                            countries: countries, selectedCountryId: selectedCountryId, flagCode: flagCode, dialCode: dialCode,
                            menuExpanded: $menuExpanded,
                            onCountrySelected: { country in
                                applyCountry(country)
                                menuExpanded = false
                            },
                            phoneStartsWith: phoneStartsWith, phoneLength: phoneLength, phone: $phone,
                            canSubmit: canSubmit, onSubmit: submit
                        )
                        InlineErrorBanner(message: phoneError)
                        termsRow(accent: accent, mut: mut).padding(.top, 12)
                        SettingsPrimaryButton(text: L("login_cta"), enabled: isFormValid, loading: isLoading, action: submit).padding(.top, 16)
                        InlineErrorBanner(message: errorMessage)
                    }
                    .frame(maxWidth: 440)
                }
                .padding(.horizontal, 24)
                .padding(.top, 12)
                .padding(.bottom, 28)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .task { await loadCountries() }
        .fullScreenCover(isPresented: $showPrivacy) { PrivacyPolicyScreen(onBack: { showPrivacy = false }) }
        .fullScreenCover(isPresented: $showTerms) { TermsConditionsScreen(onBack: { showTerms = false }) }
    }

    private func termsRow(accent: Color, mut: Color) -> some View {
        HStack(alignment: .center, spacing: 8) {
            Button { acceptedTerms.toggle() } label: {
                Image(systemName: acceptedTerms ? "checkmark.square.fill" : "square")
                    .font(.system(size: 22))
                    .foregroundStyle(acceptedTerms ? accent : (wa.night ? wa.line : Color(hex: 0xD1D5DB)))
                    .frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            Text(termsText(accent: accent))
                .font(.dorr(13))
                .foregroundStyle(mut)
                .lineSpacing(4)
                .environment(\.openURL, OpenURLAction { url in
                    if url.host == "terms" { showTerms = true } else if url.host == "privacy" { showPrivacy = true }
                    return .handled
                })
            Spacer(minLength: 0)
        }
        .padding(.vertical, 4)
    }

    private func termsText(accent: Color) -> AttributedString {
        var text = AttributedString(L("login_terms_prefix"))
        var use = AttributedString(L("login_terms_use"))
        use.link = URL(string: "dorrpartners://terms")
        use.foregroundColor = accent
        use.underlineStyle = .single
        use.font = .dorr(13, .semibold)
        var privacy = AttributedString(L("login_terms_privacy"))
        privacy.link = URL(string: "dorrpartners://privacy")
        privacy.foregroundColor = accent
        privacy.underlineStyle = .single
        privacy.font = .dorr(13, .semibold)
        text += use
        text += AttributedString(L("login_terms_mid"))
        text += privacy
        return text
    }

    private func applyCountry(_ country: CountryDto) {
        selectedCountryId = country.id
        flagCode = (country.flag?.code).flatMap { $0.isEmpty ? nil : $0 } ?? country.code
        dialCode = country.formattedDial
        let length = country.phoneLength ?? phoneLength
        phoneLength = length
        phoneStartsWith = country.phoneStartsWith ?? ""
        if phone.count > length { phone = String(phone.prefix(length)) }
    }

    /// One read when the page opens: the list, then the country the phone is in (by IP; the mobile network's own country
    /// when that only gives the default). A country picked by hand meanwhile is not overridden.
    private func loadCountries() async {
        guard countries.isEmpty else { return }
        let listed = await GeneralApi.countries().value ?? []
        countries = listed
        let fallback = listed.first(where: { $0.isDefault == true }) ?? listed.first
        if let fallback { applyCountry(fallback) }
        let byIp = await GeneralApi.detectCountry().value.flatMap { found in listed.first { $0.id == found.id } }
        let byNetwork = Self.deviceCountryIso().flatMap { iso in listed.first { $0.code.lowercased() == iso } }
        let chosen = (byIp?.isDefault == true ? nil : byIp) ?? byNetwork ?? byIp
        if let chosen, selectedCountryId == fallback?.id, phone.isEmpty { applyCountry(chosen) }
    }

    private func submit() {
        guard canSubmit else { return }
        isLoading = true
        errorMessage = nil
        Task {
            let result = await AuthApi.requestOtp(dialCode: dialCode, phone: phone)
            isLoading = false
            switch result {
            case .ok(let data, _): onOtpSent(dialCode, phone, data.resendCooldownSeconds ?? 60)
            default: errorMessage = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    /// The country of the SIM's carrier, as ISO alpha-2 (lowercase); nil without one.
    private static func deviceCountryIso() -> String? {
        CTTelephonyNetworkInfo().serviceSubscriberCellularProviders?.values
            .compactMap { $0.isoCountryCode?.lowercased() }
            .first { $0.count == 2 }
    }
}
