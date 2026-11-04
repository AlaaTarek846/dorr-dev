import CoreTelephony
import SwiftUI

// driverApp `ui/LoginScreen.kt`.

/// Used until the list answers (and if it never does): the launch country.
private let fallbackCountry = CountryDto(id: 0, code: "SA", name: "", dialCode: "+966", phoneLength: 9, phoneStartsWith: "5", isDefault: true)

/// Sign-in, the DORR app's way: the countries open for sign-in (with flags and a search), the country the phone is in
/// picked for you (the server's guess by IP, else the SIM's), the number checked against that country's length and
/// first digits as it is typed, the terms, then the code.
@MainActor
struct LoginScreen: View {
    @Environment(\.pal) private var pal
    let onCodeSent: (_ dialCode: String, _ phone: String, _ maskedPhone: String, _ cooldown: Int) -> Void

    @State private var countries: [CountryDto] = []
    @State private var country = fallbackCountry
    @State private var phone = ""
    @State private var accepted = false
    @State private var busy = false
    @State private var error: String?
    @State private var freezeOpen = false

    init(onCodeSent: @escaping (_ dialCode: String, _ phone: String, _ maskedPhone: String, _ cooldown: Int) -> Void) {
        self.onCodeSent = onCodeSent
    }

    private var length: Int { country.phoneLength ?? 12 }
    private var startsWith: String { country.phoneStartsWith ?? "" }

    /// What is wrong with the number, as it is typed (the country's length and first digits).
    private var phoneProblem: String? {
        if phone.isEmpty { return nil }
        if !startsWith.isEmpty && !phone.hasPrefix(startsWith) { return L("login_error_start", startsWith) }
        if phone.count != length { return L("login_error_length", length) }
        return nil
    }

    private var valid: Bool { phone.count == length && (startsWith.isEmpty || phone.hasPrefix(startsWith)) }
    private var canSubmit: Bool { valid && accepted && !busy }

    var body: some View {
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        Spacer(minLength: 0)
                        LanguagePill()
                    }
                    .frame(maxWidth: 440)
                    VSpace(8)

                    VStack(spacing: 0) {
                        Text(L("login_title"))
                            .font(.dorr(30, .heavy)).foregroundStyle(pal.accent)
                            .multilineTextAlignment(.center).lineSpacing(8)
                            .staggerIn(0)
                        VSpace(8)
                        Text(L("login_subtitle"))
                            .font(.dorr(14)).foregroundStyle(pal.mut)
                            .multilineTextAlignment(.center).lineSpacing(8)
                            .frame(maxWidth: 260)
                            .staggerIn(1)
                        DorrLogo(width: 156).padding(.top, 22).padding(.bottom, 26).staggerIn(2)

                        PhoneField(
                            countries: countries,
                            selected: country,
                            onCountry: { picked in
                                country = picked
                                let max = picked.phoneLength ?? 12
                                if phone.count > max { phone = String(phone.prefix(max)) }
                                error = nil
                            },
                            phone: phone,
                            onPhone: { phone = $0; error = nil },
                            error: phoneProblem != nil,
                            onDone: { submit() }
                        )
                        .staggerIn(3)
                        // What is wrong with the number, as it is typed (the country's length and first digits).
                        Banner(phoneProblem).padding(.top, 8)

                        VSpace(12)
                        HStack(spacing: 0) {
                            DorrIcon(accepted ? "checkmark.square.fill" : "square", size: 24, tint: accepted ? pal.accent : pal.line)
                                .frame(width: 28, height: 28)
                                .bounceClick { accepted.toggle() }
                            HSpace(8)
                            Text(L("login_terms"))
                                .font(.dorr(13)).foregroundStyle(pal.mut).lineSpacing(7)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .bounceClick { accepted.toggle() }
                        }
                        .padding(.vertical, 4)
                        .frame(maxWidth: .infinity)
                        .staggerIn(4)

                        VSpace(16)
                        PrimaryButton(L("login_send_code"), enabled: valid && accepted, busy: busy, icon: "arrow.forward", onClick: { submit() })
                            .staggerIn(5)
                        Banner(error).padding(.top, 14)
                        // D-299: a lost phone — freeze the money from here, no sign-in.
                        Text(L("wx_freeze_link"))
                            .font(.dorr(12.5, .semibold)).foregroundStyle(pal.mut)
                            .padding(6)
                            .bounceClick { freezeOpen = true }
                            .padding(.top, 14)
                    }
                    .frame(maxWidth: 440)
                }
                .frame(maxWidth: .infinity)
                .padding(.horizontal, 24)
                .padding(.top, 12)
                .padding(.bottom, 28)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .dorrDialog(isPresented: $freezeOpen) {
            EmergencyFreezeDialog(dialCode: country.dialCode ?? "", onClose: { freezeOpen = false })
        }
        .task { await loadCountries() }
    }

    private func loadCountries() async {
        let listed = await DriverApi.countries().value ?? []
        countries = listed
        let fallback = listed.first { $0.isDefault == true } ?? listed.first
        if let fallback { country = fallback }
        // The country the phone is in: the server's guess by IP; when that only gives the default, the SIM's /
        // mobile network's. Either one only if it is in the list.
        let found = await DriverApi.detectCountry().value
        let byIp = found.flatMap { f in listed.first { $0.id == f.id } }
        let byNetwork = deviceCountryIso().flatMap { iso in listed.first { ($0.code ?? "").caseInsensitiveCompare(iso) == .orderedSame } }
        let chosen = (byIp?.isDefault == true ? nil : byIp) ?? byNetwork ?? byIp
        // Never over a country picked by hand meanwhile.
        if let chosen, country.id == fallback?.id, phone.isEmpty { country = chosen }
    }

    private func submit() {
        guard canSubmit else { return }
        busy = true
        error = nil
        let dial = formatDialCode(country.dialCode ?? "")
        let number = phone
        Task {
            switch await DriverApi.requestOtp(body: PhoneBody(dialCode: dial, phone: number)) {
            case let .ok(sent, _):
                onCodeSent(dial, number, sent.maskedPhone ?? "\(dial) \(number)", sent.resendCooldownSeconds ?? 60)
            case let .failed(e):
                error = e.displayMessage
            case .offline:
                error = L("error_offline")
            }
            busy = false
        }
    }
}

/// The country of the mobile network the phone is on (else its SIM's), as ISO alpha-2; nil on Wi-Fi-only devices.
/// (iOS 16.4+ no longer tells apps the carrier: then nil, as on a Wi-Fi-only phone.)
private func deviceCountryIso() -> String? {
    let providers = CTTelephonyNetworkInfo().serviceSubscriberCellularProviders?.values.map { $0 } ?? []
    return providers.compactMap { $0.isoCountryCode }.first { $0.count == 2 && $0 != "--" }
}
